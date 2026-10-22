<?php

declare(strict_types=1);

namespace Flexipos\Organization\Tests\Features\ChangeLocationStatus;

use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Organization\Features\ChangeLocationStatus\ChangeLocationStatus;
use Flexipos\Organization\Features\ChangeLocationStatus\ChangeLocationStatusHandler;
use Flexipos\Organization\Support\OrganizationRefusal;
use Flexipos\Organization\Tests\Support\OrganizationSeed;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-014 step 9 - deactivating and reactivating a location (AC-24, AC-15, AC-20)
|--------------------------------------------------------------------------
|
| `location_in_use` has two causes and each is tried with its control: the
| default store of an ACTIVE branch refuses, the default store of an inactive
| branch deactivates; the selling location of a pending or active terminal
| refuses, a warehouse no terminal sells from deactivates. Stock on hand is
| not checked (no stock exists; FP-251 owes the refusal) and no case here
| pretends otherwise.
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

function locationStatus(string $locationId, mixed $isActive, ?string $actorId = null): void
{
    /** @var ChangeLocationStatusHandler $handler */
    $handler = app(ChangeLocationStatusHandler::class);

    $handler->handle(new ChangeLocationStatus($locationId, $isActive, $actorId));
}

function locationIsActive(string $locationId): bool
{
    return (bool) OrganizationSeed::connection()->table('locations')->where('id', $locationId)->value('is_active');
}

it('deactivates and reactivates with one whole audit row each, and no employee version change (AC-24, AC-20)', function () {
    $actor = AccessSeed::employee('ls_actor', ['display_name' => 'Status Person']);
    $branch = OrganizationSeed::branch();
    $location = OrganizationSeed::location($branch, 'WH1');
    $version = AccessSeed::permVersion($actor);

    locationStatus($location, false, $actor);

    expect(locationIsActive($location))->toBeFalse()
        ->and(OrganizationSeed::onlyAuditRow())->toBe([
            'actor_type' => 'employee',
            'actor_id' => $actor,
            'actor_name' => 'Status Person',
            'branch_id' => $branch,
            'module' => 'organization',
            'action' => 'location_deactivated',
            'subject_type' => 'location',
            'subject_id' => $location,
            'before' => ['is_active' => true],
            'after' => ['is_active' => false],
            'changed_fields' => ['is_active'],
            'user_agent' => null,
            'request_id' => null,
        ]);

    OrganizationSeed::connection()->table('audit_logs')->delete();

    locationStatus($location, true, $actor);

    expect(locationIsActive($location))->toBeTrue()
        ->and(OrganizationSeed::onlyAuditRow()['action'])->toBe('location_reactivated')
        ->and(AccessSeed::permVersion($actor))->toBe($version);
});

it('refuses a state it is already in, and a non-boolean, and an unknown location (AC-24)', function () {
    $location = OrganizationSeed::location(null, 'WH2');

    OrganizationSeed::refusal(OrganizationRefusal::Validation, fn () => locationStatus($location, true), 'activate an active one');

    locationStatus($location, false);
    OrganizationSeed::connection()->table('audit_logs')->delete();

    OrganizationSeed::refusal(OrganizationRefusal::Validation, fn () => locationStatus($location, false), 'deactivate an inactive one');

    foreach (['false', 0, null] as $bad) {
        OrganizationSeed::refusal(OrganizationRefusal::Validation, fn () => locationStatus($location, $bad));
    }

    OrganizationSeed::refusal(OrganizationRefusal::NotFound, fn () => locationStatus('01ARZ3NDEKTSV4RRFFQ69G5FAV', false));

    expect(OrganizationSeed::audit())->toBe([]);
});

it('refuses the default store of an active branch, and deactivates the default store of an inactive one (AC-24)', function () {
    $branch = OrganizationSeed::branch();
    $store = OrganizationSeed::location($branch, 'ST1', 'branch_store');

    OrganizationSeed::connection()->table('branches')->where('id', $branch)->update(['default_location_id' => $store]);

    OrganizationSeed::refusal(OrganizationRefusal::LocationInUse, fn () => locationStatus($store, false));

    expect(locationIsActive($store))->toBeTrue()
        ->and(OrganizationSeed::audit())->toBe([]);

    // The control: the same default store, its branch now inactive.
    OrganizationSeed::connection()->table('branches')->where('id', $branch)->update(['is_active' => false]);

    locationStatus($store, false);

    expect(locationIsActive($store))->toBeFalse();
});

it('refuses the selling location of a pending or active terminal and not of an inactive or revoked one (AC-24)', function (string $status, bool $blocks) {
    $branch = OrganizationSeed::branch();
    $location = OrganizationSeed::location($branch, 'SL1', 'warehouse', ['is_sellable_from' => true]);

    OrganizationSeed::terminal($branch, $location, 'T1', $status);

    if ($blocks) {
        OrganizationSeed::refusal(OrganizationRefusal::LocationInUse, fn () => locationStatus($location, false));

        expect(locationIsActive($location))->toBeTrue();

        return;
    }

    locationStatus($location, false);

    expect(locationIsActive($location))->toBeFalse();
})->with([
    'pending' => ['pending', true],
    'active' => ['active', true],
    'inactive' => ['inactive', false],
    'revoked' => ['revoked', false],
]);

it('deactivates a warehouse no terminal sells from (AC-24 control)', function () {
    $branch = OrganizationSeed::branch();
    $sells = OrganizationSeed::location($branch, 'SL2', 'warehouse', ['is_sellable_from' => true]);
    $other = OrganizationSeed::location($branch, 'SL3', 'warehouse', ['is_sellable_from' => true]);

    OrganizationSeed::terminal($branch, $sells, 'T1', 'active');

    locationStatus($other, false);

    expect(locationIsActive($other))->toBeFalse()
        ->and(locationIsActive($sells))->toBeTrue();
});

it('refuses to reactivate a location whose branch is inactive, and reactivates it once the branch is (AC-24, AC-15)', function () {
    $branch = OrganizationSeed::branch();
    $location = OrganizationSeed::location($branch, 'WH3', 'warehouse', ['is_active' => false]);

    OrganizationSeed::connection()->table('branches')->where('id', $branch)->update(['is_active' => false]);

    OrganizationSeed::refusal(OrganizationRefusal::BranchInactive, fn () => locationStatus($location, true));

    expect(locationIsActive($location))->toBeFalse()
        ->and(OrganizationSeed::audit())->toBe([]);

    OrganizationSeed::connection()->table('branches')->where('id', $branch)->update(['is_active' => true]);

    locationStatus($location, true);

    expect(locationIsActive($location))->toBeTrue();

    // A central warehouse has no branch to be inactive.
    $central = OrganizationSeed::location(null, 'CW2', 'warehouse', ['is_active' => false]);

    locationStatus($central, true);

    expect(locationIsActive($central))->toBeTrue();
});
