<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Tests\Features\AttachModifierGroup;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Catalog\Features\AttachModifierGroup\AttachModifierGroup;
use Flexipos\Catalog\Features\AttachModifierGroup\AttachModifierGroupHandler;
use Flexipos\Catalog\Features\CreateModifierGroup\CreateModifierGroup;
use Flexipos\Catalog\Features\CreateModifierGroup\CreateModifierGroupHandler;
use Flexipos\Catalog\Support\CatalogRefusal;
use Flexipos\Catalog\Tests\Support\CatalogRefusals;
use Flexipos\Catalog\Tests\Support\CatalogSeed;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-027 plan step 17 - attaching a modifier group to a product (AC-39)
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

function catalogAttachGroup(): string
{
    /** @var CreateModifierGroupHandler $handler */
    $handler = app(CreateModifierGroupHandler::class);

    return $handler->handle(new CreateModifierGroup(['name' => 'Size']))->groupId;
}

it('attaches a group to a product, and writes one audit row', function () {
    $each = CatalogSeed::unit('each');
    $category = CatalogSeed::category('drinks');
    $product = CatalogSeed::product('sku1', $category, $each);
    $group = catalogAttachGroup();

    /** @var AttachModifierGroupHandler $handler */
    $handler = app(AttachModifierGroupHandler::class);
    $handler->handle(new AttachModifierGroup(['product_id' => $product, 'group_id' => $group]));

    expect(CatalogSeed::connection()->table('product_modifier_groups')->where('product_id', $product)->where('group_id', $group)->exists())->toBeTrue();

    $audit = CatalogSeed::audit();
    expect($audit)->toHaveCount(2)
        ->and($audit[1])->toMatchArray([
            'module' => 'catalog',
            'action' => 'catalog_modifier_group_attached',
            'subject_type' => 'product_modifier_group',
            'subject_id' => "{$product}:{$group}",
        ]);
});

it('refuses a duplicate attach', function () {
    $each = CatalogSeed::unit('each');
    $category = CatalogSeed::category('drinks');
    $product = CatalogSeed::product('sku1', $category, $each);
    $group = catalogAttachGroup();

    /** @var AttachModifierGroupHandler $handler */
    $handler = app(AttachModifierGroupHandler::class);
    $handler->handle(new AttachModifierGroup(['product_id' => $product, 'group_id' => $group]));

    CatalogRefusals::expect(CatalogRefusal::Duplicate, fn () => $handler->handle(new AttachModifierGroup(['product_id' => $product, 'group_id' => $group])));
});

it('refuses a soft-deleted side', function () {
    $each = CatalogSeed::unit('each');
    $category = CatalogSeed::category('drinks');
    $product = CatalogSeed::product('sku1', $category, $each, ['deleted_at' => CatalogSeed::NOW]);
    $group = catalogAttachGroup();

    /** @var AttachModifierGroupHandler $handler */
    $handler = app(AttachModifierGroupHandler::class);

    CatalogRefusals::expect(CatalogRefusal::NotFound, fn () => $handler->handle(new AttachModifierGroup(['product_id' => $product, 'group_id' => $group])));
});

it('refuses an unknown product or group', function () {
    $group = catalogAttachGroup();

    /** @var AttachModifierGroupHandler $handler */
    $handler = app(AttachModifierGroupHandler::class);

    CatalogRefusals::expect(CatalogRefusal::NotFound, fn () => $handler->handle(new AttachModifierGroup(['product_id' => (string) Str::ulid(), 'group_id' => $group])));
});
