<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Tests\Features\DetachModifierGroup;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Catalog\Features\AttachModifierGroup\AttachModifierGroup;
use Flexipos\Catalog\Features\AttachModifierGroup\AttachModifierGroupHandler;
use Flexipos\Catalog\Features\CreateModifierGroup\CreateModifierGroup;
use Flexipos\Catalog\Features\CreateModifierGroup\CreateModifierGroupHandler;
use Flexipos\Catalog\Features\DetachModifierGroup\DetachModifierGroup;
use Flexipos\Catalog\Features\DetachModifierGroup\DetachModifierGroupHandler;
use Flexipos\Catalog\Support\CatalogRefusal;
use Flexipos\Catalog\Tests\Support\CatalogRefusals;
use Flexipos\Catalog\Tests\Support\CatalogSeed;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-027 plan step 17 - detaching a modifier group from a product
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

it('detaches a group and writes one audit row', function () {
    $each = CatalogSeed::unit('each');
    $category = CatalogSeed::category('drinks');
    $product = CatalogSeed::product('sku1', $category, $each);

    /** @var CreateModifierGroupHandler $groupHandler */
    $groupHandler = app(CreateModifierGroupHandler::class);
    $group = $groupHandler->handle(new CreateModifierGroup(['name' => 'Size']))->groupId;

    /** @var AttachModifierGroupHandler $attachHandler */
    $attachHandler = app(AttachModifierGroupHandler::class);
    $attachHandler->handle(new AttachModifierGroup(['product_id' => $product, 'group_id' => $group]));

    /** @var DetachModifierGroupHandler $handler */
    $handler = app(DetachModifierGroupHandler::class);
    $handler->handle(new DetachModifierGroup($product, $group));

    expect(CatalogSeed::connection()->table('product_modifier_groups')->where('product_id', $product)->where('group_id', $group)->exists())->toBeFalse();

    $audit = CatalogSeed::audit();
    expect($audit)->toHaveCount(3)
        ->and($audit[2]['action'])->toBe('catalog_modifier_group_detached');
});

it('refuses an unknown pair', function () {
    /** @var DetachModifierGroupHandler $handler */
    $handler = app(DetachModifierGroupHandler::class);

    CatalogRefusals::expect(CatalogRefusal::NotFound, fn () => $handler->handle(new DetachModifierGroup((string) Str::ulid(), (string) Str::ulid())));
});
