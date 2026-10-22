<?php

declare(strict_types=1);

namespace Flexipos\Organization\Tests\Features\ListLocations;

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Organization\Features\ListLocations\ListLocations;
use Flexipos\Organization\Features\ListLocations\ListLocationsHandler;
use Flexipos\Organization\Support\OrganizationRefusal;
use Flexipos\Organization\Tests\Support\OrganizationSeed;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-014 step 9 - listing locations (AC-25)
|--------------------------------------------------------------------------
|
| The control AC-25 names is the one that carries the filter: filtering by a
| branch excludes the null-branch warehouses, so a central warehouse is in
| no branch's list.
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
 * @return array<string, string>
 */
function listLocationsWorld(): array
{
    $branch = OrganizationSeed::branch();
    $other = OrganizationSeed::branch();

    return [
        'branch' => $branch,
        'other' => $other,
        'zebra' => OrganizationSeed::location($branch, 'ZEB', 'warehouse'),
        'store' => OrganizationSeed::location($branch, 'AAA', 'branch_store'),
        'central' => OrganizationSeed::location(null, 'MID', 'warehouse'),
        'inactive' => OrganizationSeed::location($other, 'BBB', 'warehouse', ['is_active' => false]),
        'gone' => OrganizationSeed::location(null, 'GON', 'warehouse', ['deleted_at' => '2026-10-22 00:00:00.000']),
    ];
}

it('lists by code, with inactive rows and their flag, and without a soft-deleted one (AC-25)', function () {
    $ids = listLocationsWorld();

    /** @var ListLocationsHandler $handler */
    $handler = app(ListLocationsHandler::class);

    $list = $handler->handle(new ListLocations);

    expect(array_column($list, 'code'))->toBe(['AAA', 'BBB', 'MID', 'ZEB'])
        ->and(array_column($list, 'is_active'))->toBe([true, false, true, true])
        ->and(array_column($list, 'id'))->not->toContain($ids['gone']);
});

it('filters by branch and excludes the null-branch warehouses, and filters by type (AC-25)', function () {
    $ids = listLocationsWorld();

    /** @var ListLocationsHandler $handler */
    $handler = app(ListLocationsHandler::class);

    $byBranch = $handler->handle(new ListLocations(branchId: strtolower($ids['branch'])));

    expect(array_column($byBranch, 'code'))->toBe(['AAA', 'ZEB'])
        ->and(array_column($byBranch, 'id'))->not->toContain($ids['central']);

    $stores = $handler->handle(new ListLocations(type: 'branch_store'));

    expect(array_column($stores, 'code'))->toBe(['AAA']);

    $both = $handler->handle(new ListLocations(branchId: $ids['other'], type: 'warehouse'));

    expect(array_column($both, 'code'))->toBe(['BBB']);
});

it('refuses an unknown type filter and a malformed branch filter as validation, not as an empty list (AC-25)', function () {
    listLocationsWorld();

    /** @var ListLocationsHandler $handler */
    $handler = app(ListLocationsHandler::class);

    OrganizationSeed::refusal(OrganizationRefusal::Validation, fn () => $handler->handle(new ListLocations(type: 'freezer')));
    OrganizationSeed::refusal(OrganizationRefusal::Validation, fn () => $handler->handle(new ListLocations(type: ['x'])));
    OrganizationSeed::refusal(OrganizationRefusal::Validation, fn () => $handler->handle(new ListLocations(branchId: 'branch-7')));
});

it('clamps the page to the configured maximum (AC-25)', function () {
    listLocationsWorld();

    config()->set('organization.page_size', 2);
    config()->set('organization.max_page_size', 3);

    /** @var ListLocationsHandler $handler */
    $handler = app(ListLocationsHandler::class);

    expect($handler->handle(new ListLocations))->toHaveCount(2)
        ->and($handler->handle(new ListLocations(1)))->toHaveCount(1)
        ->and($handler->handle(new ListLocations(500)))->toHaveCount(3);
});
