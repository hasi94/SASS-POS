<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Tests\Features\RemoveModifier;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Catalog\Features\CreateModifier\CreateModifier;
use Flexipos\Catalog\Features\CreateModifier\CreateModifierHandler;
use Flexipos\Catalog\Features\CreateModifierGroup\CreateModifierGroup;
use Flexipos\Catalog\Features\CreateModifierGroup\CreateModifierGroupHandler;
use Flexipos\Catalog\Features\RemoveModifier\RemoveModifier;
use Flexipos\Catalog\Features\RemoveModifier\RemoveModifierHandler;
use Flexipos\Catalog\Support\CatalogRefusal;
use Flexipos\Catalog\Tests\Support\CatalogRefusals;
use Flexipos\Catalog\Tests\Support\CatalogSeed;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-027 plan step 17 - removing a modifier
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

it('removes a modifier and writes one audit row', function () {
    /** @var CreateModifierGroupHandler $groupHandler */
    $groupHandler = app(CreateModifierGroupHandler::class);
    $group = $groupHandler->handle(new CreateModifierGroup(['name' => 'Size']))->groupId;

    /** @var CreateModifierHandler $createHandler */
    $createHandler = app(CreateModifierHandler::class);
    $modifierId = $createHandler->handle(new CreateModifier(['group_id' => $group, 'name' => 'Large']))->modifierId;

    /** @var RemoveModifierHandler $handler */
    $handler = app(RemoveModifierHandler::class);
    $handler->handle(new RemoveModifier($modifierId));

    expect(CatalogSeed::connection()->table('modifiers')->where('id', $modifierId)->exists())->toBeFalse();

    $audit = CatalogSeed::audit();
    expect($audit)->toHaveCount(3)
        ->and($audit[2]['action'])->toBe('catalog_modifier_removed');
});

it('refuses an unknown modifier', function () {
    /** @var RemoveModifierHandler $handler */
    $handler = app(RemoveModifierHandler::class);

    CatalogRefusals::expect(CatalogRefusal::NotFound, fn () => $handler->handle(new RemoveModifier((string) Str::ulid())));
});
