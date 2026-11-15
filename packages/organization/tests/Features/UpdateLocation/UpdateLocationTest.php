<?php

declare(strict_types=1);

namespace Flexipos\Organization\Tests\Features\UpdateLocation;

use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Organization\Features\UpdateLocation\UpdateLocation;
use Flexipos\Organization\Features\UpdateLocation\UpdateLocationHandler;
use Flexipos\Organization\Support\OrganizationRefusal;
use Flexipos\Organization\Tests\Support\OrganizationSeed;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-014 step 9 - updating a location (AC-23, AC-20)
|--------------------------------------------------------------------------
|
| A field the operation does not take is refused and changes nothing, and its
| control is the same request without it. The refusal of a stored value is
| read from the row, and the audit table must hold no row after a refusal.
*/

uses(TestCase::class);

beforeEach(function () {
    AccessTenantFixture::boot();
    AccessTenantFixture::enter('tenant-a');

    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-10-22 10:00:00.000'));
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
function updateLocation(string $locationId, array $fields, ?string $actorId = null): void
{
    /** @var UpdateLocationHandler $handler */
    $handler = app(UpdateLocationHandler::class);

    $handler->handle(new UpdateLocation($locationId, $fields, $actorId));
}

it('changes the editable fields, writes one audit row and reports only what moved (AC-23, AC-20)', function () {
    $actor = AccessSeed::employee('loc_actor', ['display_name' => 'Location Person']);
    $manager = AccessSeed::employee('loc_manager');
    $branch = OrganizationSeed::branch();
    $location = OrganizationSeed::location($branch, 'WH1');

    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-10-22 12:00:00.000'));

    updateLocation($location, [
        'name' => 'Renamed',
        'address' => ['city' => 'Kandy'],
        'manager_employee_id' => $manager,
        'allow_negative_stock' => true,
        'is_sellable_from' => true,
        'is_purchasable_to' => false,
        'sort_order' => 4,
    ], $actor);

    $row = (array) OrganizationSeed::connection()->table('locations')->where('id', $location)->first();

    expect($row['name'])->toBe('Renamed')
        ->and(json_decode((string) $row['address'], true))->toBe(['city' => 'Kandy'])
        ->and($row['manager_employee_id'])->toBe($manager)
        ->and((bool) $row['allow_negative_stock'])->toBeTrue()
        ->and((bool) $row['is_sellable_from'])->toBeTrue()
        ->and((bool) $row['is_purchasable_to'])->toBeFalse()
        ->and((int) $row['sort_order'])->toBe(4)
        ->and($row['updated_at'])->toBe('2026-10-22 12:00:00.000')
        ->and($row['updated_by'])->toBe($actor)
        ->and($row['code'])->toBe('WH1')
        ->and($row['type'])->toBe('warehouse')
        ->and($row['branch_id'])->toBe($branch);

    $audit = OrganizationSeed::onlyAuditRow();

    expect($audit['action'])->toBe('location_updated')
        ->and($audit['branch_id'])->toBe($branch)
        ->and($audit['before']['name'])->toBe('Location WH1')
        ->and($audit['after']['name'])->toBe('Renamed')
        ->and($audit['changed_fields'])->toBe([
            'address', 'allow_negative_stock', 'is_purchasable_to', 'is_sellable_from', 'manager_employee_id', 'name', 'sort_order',
        ]);
});

it('clears the manager with null, and refuses a manager naming nobody (AC-23)', function () {
    $manager = AccessSeed::employee('clear_mgr');
    $location = OrganizationSeed::location(null, 'WH2', 'warehouse', ['manager_employee_id' => $manager]);

    OrganizationSeed::refusal(OrganizationRefusal::Validation, fn () => updateLocation($location, ['manager_employee_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV']));

    expect(OrganizationSeed::connection()->table('locations')->where('id', $location)->value('manager_employee_id'))->toBe($manager);

    updateLocation($location, ['manager_employee_id' => null]);

    expect(OrganizationSeed::connection()->table('locations')->where('id', $location)->value('manager_employee_id'))->toBeNull();
});

it('refuses the fields the operation does not take, changes nothing, and accepts the same request without them (AC-23)', function (string $field, mixed $value) {
    $location = OrganizationSeed::location(null, 'WH3');
    $before = (array) OrganizationSeed::connection()->table('locations')->where('id', $location)->first();

    OrganizationSeed::refusal(OrganizationRefusal::Validation, fn () => updateLocation($location, ['name' => 'Sneaky', $field => $value]));

    expect((array) OrganizationSeed::connection()->table('locations')->where('id', $location)->first())->toBe($before)
        ->and(OrganizationSeed::audit())->toBe([]);

    updateLocation($location, ['name' => 'Sneaky']);

    expect(OrganizationSeed::connection()->table('locations')->where('id', $location)->value('name'))->toBe('Sneaky');
})->with([
    'code' => ['code', 'NEW'],
    'type' => ['type', 'virtual'],
    'branch_id' => ['branch_id', null],
    'is_active' => ['is_active', false],
    'id' => ['id', '01ARZ3NDEKTSV4RRFFQ69G5FAV'],
]);

it('refuses an empty update, a bad flag and an unknown location, and resolves a lower-case id (AC-23)', function () {
    $location = OrganizationSeed::location(null, 'WH4');

    OrganizationSeed::refusal(OrganizationRefusal::Validation, fn () => updateLocation($location, []), 'empty');
    OrganizationSeed::refusal(OrganizationRefusal::Validation, fn () => updateLocation($location, ['allow_negative_stock' => 'true']), 'flag');
    OrganizationSeed::refusal(OrganizationRefusal::NotFound, fn () => updateLocation('01ARZ3NDEKTSV4RRFFQ69G5FAV', ['name' => 'x']), 'unknown');

    updateLocation(strtolower($location), ['name' => 'Lower']);

    expect(OrganizationSeed::connection()->table('locations')->where('id', $location)->value('name'))->toBe('Lower');
});
