<?php

declare(strict_types=1);

namespace Flexipos\Organization\Tests\Features\GetLocation;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Organization\Features\GetLocation\GetLocation;
use Flexipos\Organization\Features\GetLocation\GetLocationHandler;
use Flexipos\Organization\Support\OrganizationRefusal;
use Flexipos\Organization\Tests\Support\OrganizationSeed;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-014 step 9 - reading one location (AC-25)
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

it('shows the type, the branch (null shown as null), the flags and the manager id (AC-25)', function () {
    $branch = OrganizationSeed::branch();
    $store = OrganizationSeed::location($branch, 'ST1', 'branch_store', ['is_sellable_from' => true]);
    $central = OrganizationSeed::location(null, 'CW1');

    /** @var GetLocationHandler $handler */
    $handler = app(GetLocationHandler::class);

    $view = $handler->handle(new GetLocation(strtolower($store)));
    $none = $handler->handle(new GetLocation($central));

    expect(array_keys($view))->toBe([
        'id', 'branch_id', 'code', 'name', 'type', 'address', 'manager_employee_id', 'allow_negative_stock',
        'is_sellable_from', 'is_purchasable_to', 'is_active', 'sort_order', 'created_at', 'updated_at', 'created_by', 'updated_by',
    ])
        ->and($view['id'])->toBe($store)
        ->and($view['type'])->toBe('branch_store')
        ->and($view['branch_id'])->toBe($branch)
        ->and($view['is_sellable_from'])->toBeTrue()
        ->and($none['branch_id'])->toBeNull()
        ->and($none['type'])->toBe('warehouse');
});

it('answers not_found for an unknown, soft-deleted or another tenants id, and validation for a malformed one (AC-25)', function () {
    $gone = OrganizationSeed::location(null, 'GN1', 'warehouse', ['deleted_at' => '2026-10-22 00:00:00.000']);

    /** @var GetLocationHandler $handler */
    $handler = app(GetLocationHandler::class);

    OrganizationSeed::refusal(OrganizationRefusal::NotFound, fn () => $handler->handle(new GetLocation('01ARZ3NDEKTSV4RRFFQ69G5FAV')), 'unknown');
    OrganizationSeed::refusal(OrganizationRefusal::NotFound, fn () => $handler->handle(new GetLocation($gone)), 'soft-deleted');
    OrganizationSeed::refusal(OrganizationRefusal::Validation, fn () => $handler->handle(new GetLocation('nope')), 'malformed');

    AccessTenantFixture::switchTo('tenant-b');
    $other = OrganizationSeed::location(null, 'OT1');
    AccessTenantFixture::switchTo('tenant-a');

    OrganizationSeed::refusal(OrganizationRefusal::NotFound, fn () => $handler->handle(new GetLocation($other)), 'another tenant');
});
