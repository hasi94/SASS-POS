<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Tests\Features\DeleteCategory;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Catalog\Features\DeleteCategory\DeleteCategory;
use Flexipos\Catalog\Features\DeleteCategory\DeleteCategoryHandler;
use Flexipos\Catalog\Support\CatalogRefusal;
use Flexipos\Catalog\Tests\Support\CatalogRefusals;
use Flexipos\Catalog\Tests\Support\CatalogSeed;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-027 plan step 9 - deleting a category (AC-5)
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

function deleteCategory(string $categoryId, ?string $actorId = null): void
{
    /** @var DeleteCategoryHandler $handler */
    $handler = app(DeleteCategoryHandler::class);

    $handler->handle(new DeleteCategory($categoryId, $actorId));
}

it('soft-deletes a category and writes one audit row', function () {
    $id = CatalogSeed::category('drinks');

    deleteCategory($id);

    $row = (array) CatalogSeed::connection()->table('categories')->where('id', $id)->first();
    expect($row['deleted_at'])->not->toBeNull();

    expect(CatalogSeed::onlyAuditRow())->toMatchArray([
        'module' => 'catalog',
        'action' => 'catalog_category_deleted',
        'subject_type' => 'category',
        'subject_id' => $id,
    ]);
});

it('refuses an unknown category', function () {
    CatalogRefusals::expect(CatalogRefusal::NotFound, fn () => deleteCategory((string) Str::ulid()));
});

it('refuses deletion while a live child category exists; a soft-deleted child does not block', function () {
    $parent = CatalogSeed::category('drinks');
    $child = CatalogSeed::category('soda', $parent);

    CatalogRefusals::expect(CatalogRefusal::InUse, fn () => deleteCategory($parent));

    CatalogSeed::connection()->table('categories')->where('id', $child)->update(['deleted_at' => CatalogSeed::NOW]);

    deleteCategory($parent);

    $row = (array) CatalogSeed::connection()->table('categories')->where('id', $parent)->first();
    expect($row['deleted_at'])->not->toBeNull();
});

it('refuses deletion while a live product references the category; a soft-deleted product does not block', function () {
    $category = CatalogSeed::category('drinks');
    $each = CatalogSeed::unit('each');
    $product = CatalogSeed::product('sku1', $category, $each);

    CatalogRefusals::expect(CatalogRefusal::InUse, fn () => deleteCategory($category));

    CatalogSeed::connection()->table('products')->where('id', $product)->update(['deleted_at' => CatalogSeed::NOW]);

    deleteCategory($category);

    $row = (array) CatalogSeed::connection()->table('categories')->where('id', $category)->first();
    expect($row['deleted_at'])->not->toBeNull();
});
