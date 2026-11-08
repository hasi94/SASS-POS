<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Tests\Features\CreateModifierGroup;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Catalog\Features\CreateModifierGroup\CreateModifierGroup;
use Flexipos\Catalog\Features\CreateModifierGroup\CreateModifierGroupHandler;
use Flexipos\Catalog\Features\CreateModifierGroup\CreateModifierGroupResult;
use Flexipos\Catalog\Support\CatalogRefusal;
use Flexipos\Catalog\Tests\Support\CatalogRefusals;
use Flexipos\Catalog\Tests\Support\CatalogSeed;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-027 plan step 17 - creating a modifier group (AC-36)
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
function createModifierGroup(array $fields, ?string $actorId = null): CreateModifierGroupResult
{
    /** @var CreateModifierGroupHandler $handler */
    $handler = app(CreateModifierGroupHandler::class);

    return $handler->handle(new CreateModifierGroup($fields, $actorId));
}

it('defaults min_select to 0 and stores max_select as null, and writes one audit row', function () {
    $result = createModifierGroup(['name' => 'Size']);

    $row = (array) CatalogSeed::connection()->table('modifier_groups')->where('id', $result->groupId)->first();
    expect((int) $row['min_select'])->toBe(0)
        ->and($row['max_select'])->toBeNull();

    expect(CatalogSeed::onlyAuditRow())->toMatchArray([
        'module' => 'catalog',
        'action' => 'catalog_modifier_group_created',
        'subject_type' => 'modifier_group',
        'subject_id' => $result->groupId,
    ]);
});

it('refuses max_select below min_select, and accepts max_select null with any min', function () {
    CatalogRefusals::expect(CatalogRefusal::Validation, fn () => createModifierGroup(['name' => 'Extras', 'min_select' => 3, 'max_select' => 2]));

    $result = createModifierGroup(['name' => 'Extras', 'min_select' => 5, 'max_select' => null]);
    expect($result->groupId)->toBeString();
});
