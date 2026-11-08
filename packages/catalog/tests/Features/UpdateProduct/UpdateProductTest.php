<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Tests\Features\UpdateProduct;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Catalog\Features\UpdateProduct\UpdateProduct;
use Flexipos\Catalog\Features\UpdateProduct\UpdateProductHandler;
use Flexipos\Catalog\Products\ProductType;
use Flexipos\Catalog\Support\CatalogRefusal;
use Flexipos\Catalog\Tests\Support\CatalogRefusals;
use Flexipos\Catalog\Tests\Support\CatalogSeed;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-027 plan step 11 - updating a product (AC-18 to AC-27)
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

/**
 * @param  array<string, mixed>  $fields
 */
function updateProduct(string $productId, array $fields, ?string $actorId = null): void
{
    /** @var UpdateProductHandler $handler */
    $handler = app(UpdateProductHandler::class);

    $handler->handle(new UpdateProduct($productId, $fields, $actorId));
}

it('updates name and increments version, writing one audit row', function () {
    $category = CatalogSeed::category('drinks');
    $each = CatalogSeed::unit('each');
    $product = CatalogSeed::product('sku1', $category, $each);

    updateProduct($product, ['name' => 'Cola Zero']);

    $row = (array) CatalogSeed::connection()->table('products')->where('id', $product)->first();
    expect($row['name'])->toBe('Cola Zero')->and((int) $row['version'])->toBe(2);

    expect(CatalogSeed::onlyAuditRow())->toMatchArray([
        'module' => 'catalog',
        'action' => 'catalog_product_updated',
        'subject_type' => 'product',
        'subject_id' => $product,
    ]);
});

it('refuses an update naming no field', function () {
    $category = CatalogSeed::category('drinks');
    $each = CatalogSeed::unit('each');
    $product = CatalogSeed::product('sku1', $category, $each);

    CatalogRefusals::expect(CatalogRefusal::Validation, fn () => updateProduct($product, []));
});

it('refuses an unknown product', function () {
    CatalogRefusals::expect(CatalogRefusal::NotFound, fn () => updateProduct((string) Str::ulid(), ['name' => 'X']));
});

it('refuses a different stock_unit_id whole, leaving the row byte-identical even when name was sent in the same request (AC-22); accepts the same value', function () {
    $category = CatalogSeed::category('drinks');
    $each = CatalogSeed::unit('each');
    $kg = CatalogSeed::unit('kg', 'mass');
    $product = CatalogSeed::product('sku1', $category, $each);

    $before = (array) CatalogSeed::connection()->table('products')->where('id', $product)->first();

    CatalogRefusals::expect(CatalogRefusal::Immutable, fn () => updateProduct($product, ['stock_unit_id' => $kg, 'name' => 'Changed Name']));

    $after = (array) CatalogSeed::connection()->table('products')->where('id', $product)->first();
    expect($after)->toBe($before);

    // Control: the same value is accepted and is a no-op on stock_unit_id.
    updateProduct($product, ['stock_unit_id' => $each, 'name' => 'Changed Name']);
    $afterControl = (array) CatalogSeed::connection()->table('products')->where('id', $product)->first();
    expect($afterControl['name'])->toBe('Changed Name')
        ->and($afterControl['stock_unit_id'])->toBe($each);
});

it('refuses a purchase unit of another dimension on update', function () {
    $category = CatalogSeed::category('drinks');
    $each = CatalogSeed::unit('each');
    $kg = CatalogSeed::unit('kg', 'mass');
    $product = CatalogSeed::product('sku1', $category, $each);

    CatalogRefusals::expect(CatalogRefusal::DimensionMismatch, fn () => updateProduct($product, ['purchase_unit_id' => $kg]));
});

it('refuses a duplicate plu_code on update, including against a soft-deleted product', function () {
    $category = CatalogSeed::category('drinks');
    $each = CatalogSeed::unit('each');
    $a = CatalogSeed::product('a', $category, $each, ['plu_code' => 'PLU1']);
    $b = CatalogSeed::product('b', $category, $each);

    CatalogRefusals::expect(CatalogRefusal::Duplicate, fn () => updateProduct($b, ['plu_code' => 'PLU1']));

    CatalogSeed::connection()->table('products')->where('id', $a)->update(['deleted_at' => CatalogSeed::NOW]);

    CatalogRefusals::expect(CatalogRefusal::Duplicate, fn () => updateProduct($b, ['plu_code' => 'PLU1']));
});

it('refuses a type change on a variant_parent with live children', function () {
    $category = CatalogSeed::category('drinks');
    $each = CatalogSeed::unit('each');
    $parent = CatalogSeed::product('parent', $category, $each, ['type' => 'variant_parent']);
    CatalogSeed::product('child', $category, $each, ['type' => 'variant', 'parent_id' => $parent]);

    CatalogRefusals::expect(CatalogRefusal::Validation, fn () => updateProduct($parent, ['type' => ProductType::Simple->value]));
});

it('increments version on every write', function () {
    $category = CatalogSeed::category('drinks');
    $each = CatalogSeed::unit('each');
    $product = CatalogSeed::product('sku1', $category, $each);

    updateProduct($product, ['name' => 'A']);
    updateProduct($product, ['name' => 'B']);

    $row = (array) CatalogSeed::connection()->table('products')->where('id', $product)->first();
    expect((int) $row['version'])->toBe(3);
});
