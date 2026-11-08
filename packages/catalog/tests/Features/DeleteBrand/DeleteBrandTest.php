<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Tests\Features\DeleteBrand;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Catalog\Features\DeleteBrand\DeleteBrand;
use Flexipos\Catalog\Features\DeleteBrand\DeleteBrandHandler;
use Flexipos\Catalog\Support\CatalogRefusal;
use Flexipos\Catalog\Tests\Support\CatalogRefusals;
use Flexipos\Catalog\Tests\Support\CatalogSeed;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-027 plan step 10 - deleting a brand (AC-8)
|--------------------------------------------------------------------------
*/

uses(TestCase::class);

beforeEach(function () {
    AccessTenantFixture::boot();
    AccessTenantFixture::enter('tenant-a');

    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-11-08 12:00:00.000'));
});

afterEach(function () {
    AccessTenantFixture::forget();
});

afterAll(function () {
    AccessTenantFixture::deleteRoot();
});

function deleteBrand(string $brandId, ?string $actorId = null): void
{
    /** @var DeleteBrandHandler $handler */
    $handler = app(DeleteBrandHandler::class);

    $handler->handle(new DeleteBrand($brandId, $actorId));
}

it('refuses deletion while a live product references the brand, and accepts it once that product is soft-deleted (AC-8)', function () {
    $brand = CatalogSeed::brand('acme');
    $each = CatalogSeed::unit('each');
    $category = CatalogSeed::category('drinks');
    $product = CatalogSeed::product('sku1', $category, $each, ['brand_id' => $brand]);

    CatalogRefusals::expect(CatalogRefusal::InUse, fn () => deleteBrand($brand));

    CatalogSeed::connection()->table('products')->where('id', $product)->update(['deleted_at' => CatalogSeed::NOW]);

    deleteBrand($brand);

    $row = (array) CatalogSeed::connection()->table('brands')->where('id', $brand)->first();
    expect($row['deleted_at'])->not->toBeNull();

    expect(CatalogSeed::onlyAuditRow())->toMatchArray([
        'module' => 'catalog',
        'action' => 'catalog_brand_deleted',
        'subject_type' => 'brand',
        'subject_id' => $brand,
    ]);
});
