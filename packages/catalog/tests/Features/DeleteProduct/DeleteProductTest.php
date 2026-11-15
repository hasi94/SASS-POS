<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Tests\Features\DeleteProduct;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Catalog\Features\DeleteProduct\DeleteProduct;
use Flexipos\Catalog\Features\DeleteProduct\DeleteProductHandler;
use Flexipos\Catalog\Support\CatalogRefusal;
use Flexipos\Catalog\Tests\Support\CatalogRefusals;
use Flexipos\Catalog\Tests\Support\CatalogSeed;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-027 plan step 11 - deleting a product (AC-20, F-4)
|--------------------------------------------------------------------------
*/

uses(TestCase::class);

beforeEach(function () {
    AccessTenantFixture::boot();
    AccessTenantFixture::enter('tenant-a');

    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-11-08 13:00:00.000'));
});

afterEach(function () {
    AccessTenantFixture::forget();
});

afterAll(function () {
    AccessTenantFixture::deleteRoot();
});

function deleteProduct(string $productId, ?string $actorId = null): void
{
    /** @var DeleteProductHandler $handler */
    $handler = app(DeleteProductHandler::class);

    $handler->handle(new DeleteProduct($productId, $actorId));
}

it('soft-deletes a product and writes one audit row', function () {
    $category = CatalogSeed::category('drinks');
    $each = CatalogSeed::unit('each');
    $product = CatalogSeed::product('sku1', $category, $each);

    deleteProduct($product);

    $row = (array) CatalogSeed::connection()->table('products')->where('id', $product)->first();
    expect($row['deleted_at'])->not->toBeNull();

    expect(CatalogSeed::onlyAuditRow())->toMatchArray([
        'module' => 'catalog',
        'action' => 'catalog_product_deleted',
        'subject_type' => 'product',
        'subject_id' => $product,
    ]);
});

it('refuses an unknown product', function () {
    CatalogRefusals::expect(CatalogRefusal::NotFound, fn () => deleteProduct((string) Str::ulid()));
});

it('refuses a variant_parent with a live variant; accepts one with only a soft-deleted variant (AC-20)', function () {
    $category = CatalogSeed::category('drinks');
    $each = CatalogSeed::unit('each');
    $parent = CatalogSeed::product('parent', $category, $each, ['type' => 'variant_parent']);
    $variant = CatalogSeed::product('variant', $category, $each, ['type' => 'variant', 'parent_id' => $parent]);

    CatalogRefusals::expect(CatalogRefusal::InUse, fn () => deleteProduct($parent));

    CatalogSeed::connection()->table('products')->where('id', $variant)->update(['deleted_at' => CatalogSeed::NOW]);

    deleteProduct($parent);

    $row = (array) CatalogSeed::connection()->table('products')->where('id', $parent)->first();
    expect($row['deleted_at'])->not->toBeNull();
});

it('refuses deletion while the product is a component of a product whose parent is not soft-deleted; accepts it once the parent is soft-deleted (section 0.2)', function () {
    $category = CatalogSeed::category('drinks');
    $each = CatalogSeed::unit('each');
    $parentProduct = CatalogSeed::product('parent-product', $category, $each);
    $component = CatalogSeed::product('component', $category, $each);

    CatalogSeed::connection()->table('product_components')->insert([
        'id' => (string) Str::ulid(), 'parent_product_id' => $parentProduct, 'component_product_id' => $component,
        'unit_id' => $each, 'quantity' => 1, 'unit_factor' => 1, 'base_quantity' => 1,
    ]);

    CatalogRefusals::expect(CatalogRefusal::InUse, fn () => deleteProduct($component));

    CatalogSeed::connection()->table('products')->where('id', $parentProduct)->update(['deleted_at' => CatalogSeed::NOW]);

    deleteProduct($component);

    $row = (array) CatalogSeed::connection()->table('products')->where('id', $component)->first();
    expect($row['deleted_at'])->not->toBeNull();
});

it('refuses deletion while the product is the linked product of a modifier', function () {
    $category = CatalogSeed::category('drinks');
    $each = CatalogSeed::unit('each');
    $linked = CatalogSeed::product('linked', $category, $each);
    $groupId = (string) Str::ulid();

    CatalogSeed::connection()->table('modifier_groups')->insert([
        'id' => $groupId, 'created_at' => CatalogSeed::NOW, 'updated_at' => CatalogSeed::NOW, 'name' => 'Extras',
    ]);

    CatalogSeed::connection()->table('modifiers')->insert([
        'id' => (string) Str::ulid(), 'group_id' => $groupId, 'name' => 'Extra',
        'linked_product_id' => $linked, 'linked_quantity' => 1, 'linked_unit_id' => $each,
    ]);

    CatalogRefusals::expect(CatalogRefusal::InUse, fn () => deleteProduct($linked));
});
