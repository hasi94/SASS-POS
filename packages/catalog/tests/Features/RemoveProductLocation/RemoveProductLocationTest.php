<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Tests\Features\RemoveProductLocation;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Catalog\Features\CreateProductLocation\CreateProductLocation;
use Flexipos\Catalog\Features\CreateProductLocation\CreateProductLocationHandler;
use Flexipos\Catalog\Features\RemoveProductLocation\RemoveProductLocation;
use Flexipos\Catalog\Features\RemoveProductLocation\RemoveProductLocationHandler;
use Flexipos\Catalog\Support\CatalogRefusal;
use Flexipos\Catalog\Tests\Support\CatalogRefusals;
use Flexipos\Catalog\Tests\Support\CatalogSeed;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-027 plan step 15 - removing product location settings (AC-32)
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

it('removes the row and writes one audit row', function () {
    $each = CatalogSeed::unit('each');
    $category = CatalogSeed::category('drinks');
    $product = CatalogSeed::product('sku1', $category, $each);
    $location = CatalogSeed::location('LOC1');

    /** @var CreateProductLocationHandler $createHandler */
    $createHandler = app(CreateProductLocationHandler::class);
    $createHandler->handle(new CreateProductLocation(['product_id' => $product, 'location_id' => $location]));

    /** @var RemoveProductLocationHandler $handler */
    $handler = app(RemoveProductLocationHandler::class);
    $handler->handle(new RemoveProductLocation($product, $location));

    expect(CatalogSeed::connection()->table('product_locations')->where('product_id', $product)->where('location_id', $location)->exists())->toBeFalse();

    $audit = CatalogSeed::audit();
    expect($audit)->toHaveCount(2)
        ->and($audit[1]['action'])->toBe('catalog_product_location_removed');
});

it('refuses an unknown pair', function () {
    /** @var RemoveProductLocationHandler $handler */
    $handler = app(RemoveProductLocationHandler::class);

    CatalogRefusals::expect(CatalogRefusal::NotFound, fn () => $handler->handle(new RemoveProductLocation((string) Str::ulid(), (string) Str::ulid())));
});
