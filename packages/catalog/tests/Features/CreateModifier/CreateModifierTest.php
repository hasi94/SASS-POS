<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Tests\Features\CreateModifier;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Catalog\Features\CreateModifier\CreateModifier;
use Flexipos\Catalog\Features\CreateModifier\CreateModifierHandler;
use Flexipos\Catalog\Features\CreateModifier\CreateModifierResult;
use Flexipos\Catalog\Features\CreateModifierGroup\CreateModifierGroup;
use Flexipos\Catalog\Features\CreateModifierGroup\CreateModifierGroupHandler;
use Flexipos\Catalog\Support\CatalogRefusal;
use Flexipos\Catalog\Tests\Support\CatalogRefusals;
use Flexipos\Catalog\Tests\Support\CatalogSeed;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-027 plan step 17 - creating a modifier (AC-37, AC-38)
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

function catalogModifierGroup(string $name = 'Size'): string
{
    /** @var CreateModifierGroupHandler $handler */
    $handler = app(CreateModifierGroupHandler::class);

    return $handler->handle(new CreateModifierGroup(['name' => $name]))->groupId;
}

/**
 * @param  array<string, mixed>  $fields
 */
function createModifier(array $fields, ?string $actorId = null): CreateModifierResult
{
    /** @var CreateModifierHandler $handler */
    $handler = app(CreateModifierHandler::class);

    return $handler->handle(new CreateModifier($fields, $actorId));
}

it('creates a modifier with no link, and writes one audit row', function () {
    $group = catalogModifierGroup();

    $result = createModifier(['group_id' => $group, 'name' => 'Large']);

    $audit = CatalogSeed::audit();
    expect($audit)->toHaveCount(2)
        ->and($audit[1])->toMatchArray([
            'module' => 'catalog',
            'action' => 'catalog_modifier_created',
            'subject_type' => 'modifier',
            'subject_id' => $result->modifierId,
        ]);
});

it('refuses each partial linked triple, and accepts all three or none (AC-37)', function () {
    $group = catalogModifierGroup();
    $each = CatalogSeed::unit('each');
    $category = CatalogSeed::category('drinks');
    $linked = CatalogSeed::product('sku1', $category, $each);

    CatalogRefusals::expect(CatalogRefusal::Validation, fn () => createModifier(['group_id' => $group, 'name' => 'A', 'linked_product_id' => $linked]));
    CatalogRefusals::expect(CatalogRefusal::Validation, fn () => createModifier(['group_id' => $group, 'name' => 'B', 'linked_product_id' => $linked, 'linked_quantity' => 1]));
    CatalogRefusals::expect(CatalogRefusal::Validation, fn () => createModifier(['group_id' => $group, 'name' => 'C', 'linked_unit_id' => $each, 'linked_quantity' => 1]));

    $all = createModifier(['group_id' => $group, 'name' => 'D', 'linked_product_id' => $linked, 'linked_quantity' => 1, 'linked_unit_id' => $each]);
    expect($all->modifierId)->toBeString();

    $none = createModifier(['group_id' => $group, 'name' => 'E']);
    expect($none->modifierId)->toBeString();
});

it('refuses a soft-deleted linked product', function () {
    $group = catalogModifierGroup();
    $each = CatalogSeed::unit('each');
    $category = CatalogSeed::category('drinks');
    $linked = CatalogSeed::product('sku1', $category, $each, ['deleted_at' => CatalogSeed::NOW]);

    CatalogRefusals::expect(CatalogRefusal::NotFound, fn () => createModifier([
        'group_id' => $group, 'name' => 'A', 'linked_product_id' => $linked, 'linked_quantity' => 1, 'linked_unit_id' => $each,
    ]));
});

it('refuses a linked_unit_id of another dimension, and accepts the same dimension (AC-38)', function () {
    $group = catalogModifierGroup();
    $each = CatalogSeed::unit('each', 'count');
    $kg = CatalogSeed::unit('kg', 'mass');
    $category = CatalogSeed::category('drinks');
    $linked = CatalogSeed::product('sku1', $category, $each);

    CatalogRefusals::expect(CatalogRefusal::DimensionMismatch, fn () => createModifier([
        'group_id' => $group, 'name' => 'A', 'linked_product_id' => $linked, 'linked_quantity' => 1, 'linked_unit_id' => $kg,
    ]));

    $result = createModifier(['group_id' => $group, 'name' => 'B', 'linked_product_id' => $linked, 'linked_quantity' => 1, 'linked_unit_id' => $each]);
    expect($result->modifierId)->toBeString();
});

it('refuses a linked_quantity of 0 or less', function () {
    $group = catalogModifierGroup();
    $each = CatalogSeed::unit('each');
    $category = CatalogSeed::category('drinks');
    $linked = CatalogSeed::product('sku1', $category, $each);

    CatalogRefusals::expect(CatalogRefusal::Validation, fn () => createModifier([
        'group_id' => $group, 'name' => 'A', 'linked_product_id' => $linked, 'linked_quantity' => 0, 'linked_unit_id' => $each,
    ]));
});
