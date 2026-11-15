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
| T-027 plan step 12 - DatabaseUnitResolver against the fixture: proving the
| three queries load what the pure UnitLadder expects (AC-9, AC-10, AC-13,
| AC-13a, AC-14, AC-15 end to end)
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

it('resolves through the bound contract via the product-scoped row (step 1)', function () {
    $each = CatalogSeed::unit('each');
    $case = CatalogSeed::unit('case');
    $category = CatalogSeed::category('drinks');
    $product = CatalogSeed::product('sku1', $category, $each);

    CatalogSeed::connection()->table('unit_conversions')->insert([
        'id' => (string) Str::ulid(), 'product_id' => $product, 'from_unit_id' => $case, 'to_unit_id' => $each, 'factor' => '12.000000',
    ]);
    CatalogSeed::connection()->table('unit_conversions')->insert([
        'id' => (string) Str::ulid(), 'product_id' => null, 'from_unit_id' => $case, 'to_unit_id' => $each, 'factor' => '24.000000',
    ]);

    /** @var UnitResolver $resolver */
    $resolver = app(UnitResolver::class);
    $resolved = $resolver->resolve($product, $case);

    expect($resolved->factor)->toBe('12.000000')
        ->and($resolved->source)->toBe(FactorSource::ProductConversion);
});

it('resolves via a global row (step 2)', function () {
    $each = CatalogSeed::unit('each');
    $case = CatalogSeed::unit('case');
    $category = CatalogSeed::category('drinks');
    $product = CatalogSeed::product('sku1', $category, $each);

    CatalogSeed::connection()->table('unit_conversions')->insert([
        'id' => (string) Str::ulid(), 'product_id' => null, 'from_unit_id' => $case, 'to_unit_id' => $each, 'factor' => '24.000000',
    ]);

    /** @var UnitResolver $resolver */
    $resolver = app(UnitResolver::class);
    $resolved = $resolver->resolve($product, $case);

    expect($resolved->factor)->toBe('24.000000')->and($resolved->source)->toBe(FactorSource::GlobalConversion);
});

it('resolves via the base-unit chain (step 3)', function () {
    $kg = CatalogSeed::unit('kg', 'mass', ['precision' => 3]);
    $g = CatalogSeed::unit('g', 'mass', ['precision' => 0, 'base_unit_id' => $kg, 'factor' => '0.001000']);
    $category = CatalogSeed::category('drinks');
    $product = CatalogSeed::product('sku1', $category, $g);

    /** @var UnitResolver $resolver */
    $resolver = app(UnitResolver::class);
    $resolved = $resolver->resolve($product, $kg);

    expect($resolved->factor)->toBe('1000.000000')->and($resolved->source)->toBe(FactorSource::UnitChain);
});

it('resolves to 1 when the line unit is the stock unit (step 4)', function () {
    $each = CatalogSeed::unit('each');
    $category = CatalogSeed::category('drinks');
    $product = CatalogSeed::product('sku1', $category, $each);

    /** @var UnitResolver $resolver */
    $resolver = app(UnitResolver::class);
    $resolved = $resolver->resolve($product, $each);

    expect($resolved->factor)->toBe('1.000000')->and($resolved->source)->toBe(FactorSource::StockUnit);
});

it('refuses no_conversion_path when nothing resolves (step 5, AC-15)', function () {
    $each = CatalogSeed::unit('each');
    $dozen = CatalogSeed::unit('dozen');
    $category = CatalogSeed::category('drinks');
    $product = CatalogSeed::product('sku1', $category, $each);

    /** @var UnitResolver $resolver */
    $resolver = app(UnitResolver::class);

    try {
        $resolver->resolve($product, $dozen);
        Assert::fail('Expected a refusal.');
    } catch (UnitResolutionRefused $refused) {
        expect($refused->reason)->toBe(UnitRefusal::NoConversionPath);
    }
});

it('refuses cross_dimension (AC-10)', function () {
    $each = CatalogSeed::unit('each');
    $kg = CatalogSeed::unit('kg', 'mass');
    $category = CatalogSeed::category('drinks');
    $product = CatalogSeed::product('sku1', $category, $each);

    /** @var UnitResolver $resolver */
    $resolver = app(UnitResolver::class);

    try {
        $resolver->resolve($product, $kg);
        Assert::fail('Expected a refusal.');
    } catch (UnitResolutionRefused $refused) {
        expect($refused->reason)->toBe(UnitRefusal::CrossDimension);
    }
});

it('still resolves a soft-deleted product (resolution is arithmetic, not sellability)', function () {
    $each = CatalogSeed::unit('each');
    $category = CatalogSeed::category('drinks');
    $product = CatalogSeed::product('sku1', $category, $each, ['deleted_at' => CatalogSeed::NOW]);

    /** @var UnitResolver $resolver */
    $resolver = app(UnitResolver::class);
    $resolved = $resolver->resolve($product, $each);

    expect($resolved->factor)->toBe('1.000000');
});

it('refuses unknown_product for an unknown product', function () {
    /** @var UnitResolver $resolver */
    $resolver = app(UnitResolver::class);

    try {
        $resolver->resolve((string) Str::ulid(), (string) Str::ulid());
        Assert::fail('Expected a refusal.');
    } catch (UnitResolutionRefused $refused) {
        expect($refused->reason)->toBe(UnitRefusal::UnknownProduct);
    }
});
