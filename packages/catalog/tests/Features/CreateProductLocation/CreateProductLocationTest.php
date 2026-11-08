<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Tests\Features\CreateProductLocation;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Catalog\Features\CreateProductLocation\CreateProductLocation;
use Flexipos\Catalog\Features\CreateProductLocation\CreateProductLocationHandler;
use Flexipos\Catalog\Support\CatalogRefusal;
use Flexipos\Catalog\Tests\Support\CatalogRefusals;
use Flexipos\Catalog\Tests\Support\CatalogSeed;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-027 plan step 15 - product location settings (AC-32)
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

/**
 * @param  array<string, mixed>  $fields
 */
function createProductLocation(array $fields, ?string $actorId = null): void
{
    /** @var CreateProductLocationHandler $handler */
    $handler = app(CreateProductLocationHandler::class);

    $handler->handle(new CreateProductLocation($fields, $actorId));
}

it('defaults the levels to 0, and writes one audit row', function () {
    $each = CatalogSeed::unit('each');
    $category = CatalogSeed::category('drinks');
    $product = CatalogSeed::product('sku1', $category, $each);
    $location = CatalogSeed::location('LOC1');

    createProductLocation(['product_id' => $product, 'location_id' => $location]);

    $row = (array) CatalogSeed::connection()->table('product_locations')->where('product_id', $product)->where('location_id', $location)->first();
    expect((float) $row['reorder_level'])->toBe(0.0)
        ->and((float) $row['reorder_qty'])->toBe(0.0)
        ->and((float) $row['max_level'])->toBe(0.0);

    expect(CatalogSeed::onlyAuditRow())->toMatchArray([
        'module' => 'catalog',
        'action' => 'catalog_product_location_created',
        'subject_type' => 'product_location',
        'subject_id' => "{$product}:{$location}",
    ]);
});

it('refuses a negative level and a value with 5 decimals', function () {
    $each = CatalogSeed::unit('each');
    $category = CatalogSeed::category('drinks');
    $product = CatalogSeed::product('sku1', $category, $each);
    $location = CatalogSeed::location('LOC1');

    CatalogRefusals::expect(CatalogRefusal::Validation, fn () => createProductLocation(['product_id' => $product, 'location_id' => $location, 'reorder_level' => '-1']));
    CatalogRefusals::expect(CatalogRefusal::Validation, fn () => createProductLocation(['product_id' => $product, 'location_id' => $location, 'max_level' => '1.00001']));
});

it('refuses a duplicate pair', function () {
    $each = CatalogSeed::unit('each');
    $category = CatalogSeed::category('drinks');
    $product = CatalogSeed::product('sku1', $category, $each);
    $location = CatalogSeed::location('LOC1');

    createProductLocation(['product_id' => $product, 'location_id' => $location]);

    CatalogRefusals::expect(CatalogRefusal::Duplicate, fn () => createProductLocation(['product_id' => $product, 'location_id' => $location]));
});

it('refuses an unknown location', function () {
    $each = CatalogSeed::unit('each');
    $category = CatalogSeed::category('drinks');
    $product = CatalogSeed::product('sku1', $category, $each);

    CatalogRefusals::expect(CatalogRefusal::NotFound, fn () => createProductLocation(['product_id' => $product, 'location_id' => (string) Str::ulid()]));
});
