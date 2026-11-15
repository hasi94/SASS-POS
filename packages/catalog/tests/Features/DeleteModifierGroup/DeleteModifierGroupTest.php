<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Tests\Features\DeleteModifierGroup;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Catalog\Features\AttachModifierGroup\AttachModifierGroup;
use Flexipos\Catalog\Features\AttachModifierGroup\AttachModifierGroupHandler;
use Flexipos\Catalog\Features\CreateModifierGroup\CreateModifierGroup;
use Flexipos\Catalog\Features\CreateModifierGroup\CreateModifierGroupHandler;
use Flexipos\Catalog\Features\DeleteModifierGroup\DeleteModifierGroup;
use Flexipos\Catalog\Features\DeleteModifierGroup\DeleteModifierGroupHandler;
use Flexipos\Catalog\Support\CatalogRefusal;
use Flexipos\Catalog\Tests\Support\CatalogRefusals;
use Flexipos\Catalog\Tests\Support\CatalogSeed;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-027 plan step 17 - deleting a modifier group (AC-36a)
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

function deleteModifierGroup(string $groupId, ?string $actorId = null): void
{
    /** @var DeleteModifierGroupHandler $handler */
    $handler = app(DeleteModifierGroupHandler::class);

    $handler->handle(new DeleteModifierGroup($groupId, $actorId));
}

it('refuses deletion while attached to a live product, and accepts it once that product is soft-deleted (AC-36a)', function () {
    $each = CatalogSeed::unit('each');
    $category = CatalogSeed::category('drinks');
    $product = CatalogSeed::product('sku1', $category, $each);

    /** @var CreateModifierGroupHandler $createHandler */
    $createHandler = app(CreateModifierGroupHandler::class);
    $group = $createHandler->handle(new CreateModifierGroup(['name' => 'Size']))->groupId;

    /** @var AttachModifierGroupHandler $attachHandler */
    $attachHandler = app(AttachModifierGroupHandler::class);
    $attachHandler->handle(new AttachModifierGroup(['product_id' => $product, 'group_id' => $group]));

    CatalogRefusals::expect(CatalogRefusal::InUse, fn () => deleteModifierGroup($group));

    CatalogSeed::connection()->table('products')->where('id', $product)->update(['deleted_at' => CatalogSeed::NOW]);

    deleteModifierGroup($group);

    $row = (array) CatalogSeed::connection()->table('modifier_groups')->where('id', $group)->first();
    expect($row['deleted_at'])->not->toBeNull();
});
