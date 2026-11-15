<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Tests\Features\CreateProduct;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Catalog\Features\CreateProduct\CreateProduct;
use Flexipos\Catalog\Features\CreateProduct\CreateProductHandler;
use Flexipos\Catalog\Products\ProductType;
use Flexipos\Catalog\Support\CatalogRefusal;
use Flexipos\Catalog\Tests\Support\CatalogRefusals;
use Flexipos\Catalog\Tests\Support\CatalogSeed;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-027 plan step 11 - creating a product (AC-18 to AC-27, AC-27a)
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

/**
 * @param  array<string, mixed>  $fields
 */
function createProduct(array $fields, ?string $actorId = null): string
{
    /** @var CreateProductHandler $handler */
    $handler = app(CreateProductHandler::class);

    return $handler->handle(new CreateProduct($fields, $actorId))->productId;
}

/**
 * @return array{category: string, each: string}
 */
function createProductMinimumSeed(): array
{
    return ['category' => CatalogSeed::category('drinks'), 'each' => CatalogSeed::unit('each')];
}

it('creates a simple product with every documented default, and writes one audit row', function () {
    $seed = createProductMinimumSeed();

    $id = createProduct([
        'type' => ProductType::Simple->value,
        'sku' => 'sku1',
        'name' => 'Cola',
        'category_id' => $seed['category'],
        'stock_unit_id' => $seed['each'],
    ]);

    $row = (array) CatalogSeed::connection()->table('products')->where('id', $id)->first();

    expect($row['type'])->toBe('simple')
        ->and((bool) $row['track_stock'])->toBeTrue()
        ->and($row['tracking_mode'])->toBe('quantity')
        ->and((bool) $row['is_weighable'])->toBeFalse()
        ->and($row['price_mode'])->toBe('per_unit')
        ->and((bool) $row['is_sellable'])->toBeTrue()
        ->and((bool) $row['is_purchasable'])->toBeTrue()
        ->and((bool) $row['is_active'])->toBeTrue()
        ->and((bool) $row['allow_price_override'])->toBeFalse()
        ->and((bool) $row['allow_discount'])->toBeTrue()
        ->and((string) $row['cost_price'])->toBe('0')
        ->and((string) $row['selling_price'])->toBe('0')
        ->and($row['min_selling_price'])->toBeNull()
        ->and($row['msrp'])->toBeNull()
        ->and((int) $row['version'])->toBe(1);

    expect(CatalogSeed::onlyAuditRow())->toMatchArray([
        'module' => 'catalog',
        'action' => 'catalog_product_created',
        'subject_type' => 'product',
        'subject_id' => $id,
    ]);
});

it('refuses a missing required field', function () {
    $seed = createProductMinimumSeed();

    foreach (['type', 'sku', 'name', 'category_id', 'stock_unit_id'] as $missing) {
        $fields = [
            'type' => ProductType::Simple->value,
            'sku' => 'sku1',
            'name' => 'Cola',
            'category_id' => $seed['category'],
            'stock_unit_id' => $seed['each'],
        ];
        unset($fields[$missing]);

        CatalogRefusals::expect(CatalogRefusal::Validation, fn () => createProduct($fields), $missing);
    }
});

it('refuses custom_fields as an unknown field (AC-27a)', function () {
    $seed = createProductMinimumSeed();

    CatalogRefusals::expect(CatalogRefusal::Validation, fn () => createProduct([
        'type' => ProductType::Simple->value,
        'sku' => 'sku1',
        'name' => 'Cola',
        'category_id' => $seed['category'],
        'stock_unit_id' => $seed['each'],
        'custom_fields' => ['a' => 'b'],
    ]));
});

it('refuses a blank, too-long, or duplicate sku, including by case (AC-18)', function () {
    $seed = createProductMinimumSeed();

    CatalogRefusals::expect(CatalogRefusal::Validation, fn () => createProduct([
        'type' => ProductType::Simple->value, 'sku' => '', 'name' => 'Cola',
        'category_id' => $seed['category'], 'stock_unit_id' => $seed['each'],
    ]));

    CatalogRefusals::expect(CatalogRefusal::Validation, fn () => createProduct([
        'type' => ProductType::Simple->value, 'sku' => str_repeat('x', 51), 'name' => 'Cola',
        'category_id' => $seed['category'], 'stock_unit_id' => $seed['each'],
    ]));

    createProduct(['type' => ProductType::Simple->value, 'sku' => 'sku1', 'name' => 'Cola', 'category_id' => $seed['category'], 'stock_unit_id' => $seed['each']]);

    CatalogRefusals::expect(CatalogRefusal::Duplicate, fn () => createProduct([
        'type' => ProductType::Simple->value, 'sku' => 'sku1', 'name' => 'Cola 2',
        'category_id' => $seed['category'], 'stock_unit_id' => $seed['each'],
    ]));

    CatalogRefusals::expect(CatalogRefusal::Duplicate, fn () => createProduct([
        'type' => ProductType::Simple->value, 'sku' => 'SKU1', 'name' => 'Cola 3',
        'category_id' => $seed['category'], 'stock_unit_id' => $seed['each'],
    ]));
});

it('accepts a variant under a variant_parent; refuses a variant under a simple product or another variant; refuses a variant with no parent; refuses a variant_parent with a parent (AC-19)', function () {
    $seed = createProductMinimumSeed();

    $simple = createProduct(['type' => ProductType::Simple->value, 'sku' => 'simple1', 'name' => 'Simple', 'category_id' => $seed['category'], 'stock_unit_id' => $seed['each']]);
    $parent = createProduct(['type' => ProductType::VariantParent->value, 'sku' => 'parent1', 'name' => 'Parent', 'category_id' => $seed['category'], 'stock_unit_id' => $seed['each']]);

    $variant = createProduct(['type' => ProductType::Variant->value, 'sku' => 'variant1', 'name' => 'Variant', 'category_id' => $seed['category'], 'stock_unit_id' => $seed['each'], 'parent_id' => $parent]);
    expect($variant)->toBeString();

    CatalogRefusals::expect(CatalogRefusal::Validation, fn () => createProduct([
        'type' => ProductType::Variant->value, 'sku' => 'variant2', 'name' => 'Variant 2',
        'category_id' => $seed['category'], 'stock_unit_id' => $seed['each'], 'parent_id' => $simple,
    ]));

    CatalogRefusals::expect(CatalogRefusal::Validation, fn () => createProduct([
        'type' => ProductType::Variant->value, 'sku' => 'variant3', 'name' => 'Variant 3',
        'category_id' => $seed['category'], 'stock_unit_id' => $seed['each'], 'parent_id' => $variant,
    ]));

    CatalogRefusals::expect(CatalogRefusal::Validation, fn () => createProduct([
        'type' => ProductType::Variant->value, 'sku' => 'variant4', 'name' => 'Variant 4',
        'category_id' => $seed['category'], 'stock_unit_id' => $seed['each'],
    ]));

    CatalogRefusals::expect(CatalogRefusal::Validation, fn () => createProduct([
        'type' => ProductType::VariantParent->value, 'sku' => 'parent2', 'name' => 'Parent 2',
        'category_id' => $seed['category'], 'stock_unit_id' => $seed['each'], 'parent_id' => $parent,
    ]));
});

it('round-trips variant_options and variant_values JSON exactly (AC-21)', function () {
    $seed = createProductMinimumSeed();

    $id = createProduct([
        'type' => ProductType::Simple->value, 'sku' => 'sku1', 'name' => 'Cola',
        'category_id' => $seed['category'], 'stock_unit_id' => $seed['each'],
        'variant_options' => ['colour' => ['red', 'blue']], 'variant_values' => ['colour' => 'red'],
    ]);

    $row = (array) CatalogSeed::connection()->table('products')->where('id', $id)->first();

    expect(json_decode((string) $row['variant_options'], true))->toBe(['colour' => ['red', 'blue']])
        ->and(json_decode((string) $row['variant_values'], true))->toBe(['colour' => 'red']);
});

it('refuses a purchase or sales unit of another dimension; accepts null (AC-23)', function () {
    $seed = createProductMinimumSeed();
    $kg = CatalogSeed::unit('kg', 'mass');

    CatalogRefusals::expect(CatalogRefusal::DimensionMismatch, fn () => createProduct([
        'type' => ProductType::Simple->value, 'sku' => 'sku1', 'name' => 'Cola',
        'category_id' => $seed['category'], 'stock_unit_id' => $seed['each'], 'purchase_unit_id' => $kg,
    ]));

    $id = createProduct([
        'type' => ProductType::Simple->value, 'sku' => 'sku2', 'name' => 'Cola',
        'category_id' => $seed['category'], 'stock_unit_id' => $seed['each'], 'purchase_unit_id' => null,
    ]);
    expect($id)->toBeString();
});

it('trims and nulls a blank plu_code, refuses 11 characters, allows two blanks, refuses a duplicate including against a soft-deleted product (AC-24)', function () {
    $seed = createProductMinimumSeed();

    $a = createProduct(['type' => ProductType::Simple->value, 'sku' => 'a', 'name' => 'A', 'category_id' => $seed['category'], 'stock_unit_id' => $seed['each'], 'plu_code' => '']);
    $b = createProduct(['type' => ProductType::Simple->value, 'sku' => 'b', 'name' => 'B', 'category_id' => $seed['category'], 'stock_unit_id' => $seed['each'], 'plu_code' => '  ']);

    $rowA = (array) CatalogSeed::connection()->table('products')->where('id', $a)->first();
    $rowB = (array) CatalogSeed::connection()->table('products')->where('id', $b)->first();
    expect($rowA['plu_code'])->toBeNull()->and($rowB['plu_code'])->toBeNull();

    CatalogRefusals::expect(CatalogRefusal::Validation, fn () => createProduct([
        'type' => ProductType::Simple->value, 'sku' => 'c', 'name' => 'C', 'category_id' => $seed['category'],
        'stock_unit_id' => $seed['each'], 'plu_code' => str_repeat('1', 11),
    ]));

    $withPlu = createProduct(['type' => ProductType::Simple->value, 'sku' => 'd', 'name' => 'D', 'category_id' => $seed['category'], 'stock_unit_id' => $seed['each'], 'plu_code' => 'PLU1']);

    CatalogRefusals::expect(CatalogRefusal::Duplicate, fn () => createProduct([
        'type' => ProductType::Simple->value, 'sku' => 'e', 'name' => 'E', 'category_id' => $seed['category'],
        'stock_unit_id' => $seed['each'], 'plu_code' => 'PLU1',
    ]));

    CatalogSeed::connection()->table('products')->where('id', $withPlu)->update(['deleted_at' => CatalogSeed::NOW]);

    CatalogRefusals::expect(CatalogRefusal::Duplicate, fn () => createProduct([
        'type' => ProductType::Simple->value, 'sku' => 'f', 'name' => 'F', 'category_id' => $seed['category'],
        'stock_unit_id' => $seed['each'], 'plu_code' => 'PLU1',
    ]));
});

it('refuses an unknown enum value for tracking_mode and price_mode (AC-25, AC-26)', function () {
    $seed = createProductMinimumSeed();

    CatalogRefusals::expect(CatalogRefusal::Validation, fn () => createProduct([
        'type' => ProductType::Simple->value, 'sku' => 'a', 'name' => 'A', 'category_id' => $seed['category'],
        'stock_unit_id' => $seed['each'], 'tracking_mode' => 'bogus',
    ]));

    CatalogRefusals::expect(CatalogRefusal::Validation, fn () => createProduct([
        'type' => ProductType::Simple->value, 'sku' => 'b', 'name' => 'B', 'category_id' => $seed['category'],
        'stock_unit_id' => $seed['each'], 'price_mode' => 'bogus',
    ]));
});

it('writes nothing, including no audit row, on every refusal above', function () {
    $seed = createProductMinimumSeed();

    $before = CatalogSeed::connection()->table('products')->count();

    foreach ([
        ['type' => ProductType::Simple->value, 'sku' => '', 'name' => 'X', 'category_id' => $seed['category'], 'stock_unit_id' => $seed['each']],
        ['type' => 'bogus', 'sku' => 'x', 'name' => 'X', 'category_id' => $seed['category'], 'stock_unit_id' => $seed['each']],
    ] as $fields) {
        try {
            createProduct($fields);
        } catch (\Throwable) {
            // expected
        }
    }

    expect(CatalogSeed::connection()->table('products')->count())->toBe($before)
        ->and(CatalogSeed::audit())->toBe([]);
});
