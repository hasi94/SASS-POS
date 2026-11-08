<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Tests\Features\AddProductComponent;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Catalog\Features\AddProductComponent\AddProductComponent;
use Flexipos\Catalog\Features\AddProductComponent\AddProductComponentHandler;
use Flexipos\Catalog\Features\AddProductComponent\AddProductComponentResult;
use Flexipos\Catalog\Support\CatalogRefusal;
use Flexipos\Catalog\Tests\Support\CatalogRefusals;
use Flexipos\Catalog\Tests\Support\CatalogSeed;
use Flexipos\Contracts\Catalog\UnitResolver;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-027 plan step 16 - adding a product component (AC-34, AC-34a)
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
function addProductComponent(array $fields, ?string $actorId = null): AddProductComponentResult
{
    /** @var AddProductComponentHandler $handler */
    $handler = app(AddProductComponentHandler::class);

    return $handler->handle(new AddProductComponent($fields, $actorId));
}

it('snapshots the UOM quad through the contract, and writes one audit row', function () {
    $kg = CatalogSeed::unit('kg', 'mass', ['precision' => 3]);
    $g = CatalogSeed::unit('g', 'mass', ['precision' => 0, 'base_unit_id' => $kg, 'factor' => '0.001000']);
    $category = CatalogSeed::category('drinks');
    $parent = CatalogSeed::product('bundle', $category, $kg);
    $component = CatalogSeed::product('flour', $category, $g);

    $result = addProductComponent([
        'parent_product_id' => $parent,
        'component_product_id' => $component,
        'unit_id' => $kg,
        'quantity' => '0.25',
    ]);

    $row = (array) CatalogSeed::connection()->table('product_components')->where('id', $result->componentId)->first();
    expect((float) $row['unit_factor'])->toBe(1000.0)
        ->and((float) $row['base_quantity'])->toBe(250.0)
        ->and((string) $row['unit_id'])->toBe($kg);

    expect(CatalogSeed::onlyAuditRow())->toMatchArray([
        'module' => 'catalog',
        'action' => 'catalog_product_component_added',
        'subject_type' => 'product_component',
        'subject_id' => $result->componentId,
    ]);
});

it('refuses a self-component', function () {
    $each = CatalogSeed::unit('each');
    $category = CatalogSeed::category('drinks');
    $product = CatalogSeed::product('sku1', $category, $each);

    CatalogRefusals::expect(CatalogRefusal::Validation, fn () => addProductComponent([
        'parent_product_id' => $product, 'component_product_id' => $product, 'unit_id' => $each, 'quantity' => 1,
    ]));
});

it('refuses a duplicate pair', function () {
    $each = CatalogSeed::unit('each');
    $category = CatalogSeed::category('drinks');
    $parent = CatalogSeed::product('bundle', $category, $each);
    $component = CatalogSeed::product('part', $category, $each);

    addProductComponent(['parent_product_id' => $parent, 'component_product_id' => $component, 'unit_id' => $each, 'quantity' => 1]);

    CatalogRefusals::expect(CatalogRefusal::Duplicate, fn () => addProductComponent([
        'parent_product_id' => $parent, 'component_product_id' => $component, 'unit_id' => $each, 'quantity' => 1,
    ]));
});

it('refuses a direct cycle: A contains B, then B contains A (AC-34a)', function () {
    $each = CatalogSeed::unit('each');
    $category = CatalogSeed::category('drinks');
    $a = CatalogSeed::product('a', $category, $each);
    $b = CatalogSeed::product('b', $category, $each);

    addProductComponent(['parent_product_id' => $a, 'component_product_id' => $b, 'unit_id' => $each, 'quantity' => 1]);

    $refused = CatalogRefusals::expect(CatalogRefusal::Cycle, fn () => addProductComponent([
        'parent_product_id' => $b, 'component_product_id' => $a, 'unit_id' => $each, 'quantity' => 1,
    ]));
    expect($refused->getMessage())->toContain($a)->toContain($b);
});

it('refuses a transitive cycle: A contains B contains C, then C contains A (AC-34a)', function () {
    $each = CatalogSeed::unit('each');
    $category = CatalogSeed::category('drinks');
    $a = CatalogSeed::product('a', $category, $each);
    $b = CatalogSeed::product('b', $category, $each);
    $c = CatalogSeed::product('c', $category, $each);

    addProductComponent(['parent_product_id' => $a, 'component_product_id' => $b, 'unit_id' => $each, 'quantity' => 1]);
    addProductComponent(['parent_product_id' => $b, 'component_product_id' => $c, 'unit_id' => $each, 'quantity' => 1]);

    CatalogRefusals::expect(CatalogRefusal::Cycle, fn () => addProductComponent([
        'parent_product_id' => $c, 'component_product_id' => $a, 'unit_id' => $each, 'quantity' => 1,
    ]));
});

it('accepts a diamond: A contains B, A contains C, then B contains C', function () {
    $each = CatalogSeed::unit('each');
    $category = CatalogSeed::category('drinks');
    $a = CatalogSeed::product('a', $category, $each);
    $b = CatalogSeed::product('b', $category, $each);
    $c = CatalogSeed::product('c', $category, $each);

    addProductComponent(['parent_product_id' => $a, 'component_product_id' => $b, 'unit_id' => $each, 'quantity' => 1]);
    addProductComponent(['parent_product_id' => $a, 'component_product_id' => $c, 'unit_id' => $each, 'quantity' => 1]);

    $result = addProductComponent(['parent_product_id' => $b, 'component_product_id' => $c, 'unit_id' => $each, 'quantity' => 1]);
    expect($result->componentId)->toBeString();
});

it('freezes the factor: a later conversion or unit change does not retro-change an already-written row', function () {
    $kg = CatalogSeed::unit('kg', 'mass', ['precision' => 3]);
    $g = CatalogSeed::unit('g', 'mass', ['precision' => 0, 'base_unit_id' => $kg, 'factor' => '0.001000']);
    $category = CatalogSeed::category('drinks');
    $parent = CatalogSeed::product('bundle', $category, $kg);
    $component = CatalogSeed::product('flour', $category, $g);

    $result = addProductComponent([
        'parent_product_id' => $parent, 'component_product_id' => $component, 'unit_id' => $kg, 'quantity' => '0.25',
    ]);

    CatalogSeed::connection()->table('units')->where('id', $g)->update(['factor' => '0.002000']);

    $row = (array) CatalogSeed::connection()->table('product_components')->where('id', $result->componentId)->first();
    expect((float) $row['unit_factor'])->toBe(1000.0)
        ->and((float) $row['base_quantity'])->toBe(250.0);
});

it('refuses an unknown parent or component', function () {
    $each = CatalogSeed::unit('each');
    $category = CatalogSeed::category('drinks');
    $component = CatalogSeed::product('part', $category, $each);

    CatalogRefusals::expect(CatalogRefusal::NotFound, fn () => addProductComponent([
        'parent_product_id' => (string) Str::ulid(), 'component_product_id' => $component, 'unit_id' => $each, 'quantity' => 1,
    ]));
});

it('reaches the published UnitResolver contract, never DatabaseUnitResolver directly', function () {
    $handler = app(AddProductComponentHandler::class);
    $reflection = new \ReflectionClass($handler);
    $constructor = $reflection->getConstructor();

    $types = array_map(
        static fn (\ReflectionParameter $parameter): string => (string) $parameter->getType(),
        $constructor->getParameters(),
    );

    expect($types)->toContain(UnitResolver::class);
});
