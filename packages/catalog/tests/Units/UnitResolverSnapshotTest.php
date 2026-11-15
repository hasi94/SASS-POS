<?php

namespace Flexipos\Catalog\Tests\Units;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Catalog\Tests\Support\CatalogSeed;
use Flexipos\Contracts\Catalog\FactorSource;
use Flexipos\Contracts\Catalog\UnitRefusal;
use Flexipos\Contracts\Catalog\UnitResolutionRefused;
use Flexipos\Contracts\Catalog\UnitResolver;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-027 plan step 13 - UnitResolver::snapshot() end to end, through the
| contract, against the fixture (AC-12, AC-16)
|--------------------------------------------------------------------------
*/

uses(TestCase::class);

beforeEach(function () {
    AccessTenantFixture::boot();
    AccessTenantFixture::enter('tenant-a');
});

afterEach(function () {
    AccessTenantFixture::forget();
});

afterAll(function () {
    AccessTenantFixture::deleteRoot();
});

it('rounds the line quantity first, then computes the base quantity: a case of 24 on an each product, qty 2.5, gives 3.0000 / 24.000000 / 72.0000', function () {
    $each = CatalogSeed::unit('each', 'count', ['precision' => 0]);
    $case = CatalogSeed::unit('case', 'count', ['precision' => 0]);
    $category = CatalogSeed::category('drinks');
    $product = CatalogSeed::product('sku1', $category, $each);

    CatalogSeed::connection()->table('unit_conversions')->insert([
        'id' => (string) Str::ulid(), 'product_id' => null, 'from_unit_id' => $case, 'to_unit_id' => $each, 'factor' => '24.000000',
    ]);

    /** @var UnitResolver $resolver */
    $resolver = app(UnitResolver::class);
    $snapshot = $resolver->snapshot($product, $case, '2.5');

    expect($snapshot->quantity)->toBe('3.0000')
        ->and($snapshot->unitFactor)->toBe('24.000000')
        ->and($snapshot->baseQuantity)->toBe('72.0000')
        ->and($snapshot->unitCode)->toBe('case')
        ->and($snapshot->source)->toBe(FactorSource::GlobalConversion);
});

it('refuses factor_below_one for a g line on a kg stock unit', function () {
    $kg = CatalogSeed::unit('kg', 'mass', ['precision' => 3]);
    $g = CatalogSeed::unit('g', 'mass', ['precision' => 0, 'base_unit_id' => $kg, 'factor' => '0.001000']);
    $category = CatalogSeed::category('drinks');
    $product = CatalogSeed::product('sku1', $category, $kg);

    /** @var UnitResolver $resolver */
    $resolver = app(UnitResolver::class);

    try {
        $resolver->snapshot($product, $g, 1);
        Assert::fail('Expected a refusal.');
    } catch (UnitResolutionRefused $refused) {
        expect($refused->reason)->toBe(UnitRefusal::FactorBelowOne);
    }
});

it('refuses fractional_base_quantity on an each (precision 0) stock unit', function () {
    $each = CatalogSeed::unit('each', 'count', ['precision' => 0]);
    $third = CatalogSeed::unit('third', 'count', ['precision' => 4]);
    $category = CatalogSeed::category('drinks');
    $product = CatalogSeed::product('sku1', $category, $each);

    CatalogSeed::connection()->table('unit_conversions')->insert([
        'id' => (string) Str::ulid(), 'product_id' => null, 'from_unit_id' => $third, 'to_unit_id' => $each, 'factor' => '1.500000',
    ]);

    /** @var UnitResolver $resolver */
    $resolver = app(UnitResolver::class);

    try {
        $resolver->snapshot($product, $third, 1);
        Assert::fail('Expected a refusal.');
    } catch (UnitResolutionRefused $refused) {
        expect($refused->reason)->toBe(UnitRefusal::FractionalBaseQuantity);
    }
});
