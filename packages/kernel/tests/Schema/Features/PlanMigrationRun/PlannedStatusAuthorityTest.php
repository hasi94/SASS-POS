<?php

use Flexipos\Kernel\Schema\Exceptions\MigrationRunRefused;
use Flexipos\Kernel\Schema\Features\PlanMigrationRun\PlanMigrationRun;
use Flexipos\Kernel\Schema\Features\PlanMigrationRun\PlanMigrationRunHandler;
use Flexipos\Kernel\Tenancy\TenantConnectionPurpose;
use Flexipos\Kernel\Tenancy\TenantStatusPolicy;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-008 step 13b, AC-51: one authority for "which statuses may be migrated"
|--------------------------------------------------------------------------
|
| [FIX] BUG-1. The planner accepted four statuses and the door accepted one,
| so three of the four produced a job row that could only fail - and because
| D-12 writes `failed` after a failed offline migration, a `failed` tenant
| could never be entered and therefore never retried (plan 16.1).
|
| The fix is one authority, `TenantStatusPolicy`, resolved from the container
| and asked by the door and by the planner alike. **Two teeth are required and
| neither is sufficient**: a structural tooth passes on two lists that are
| equally wrong, and the behavioural half passes on two lists that agree by
| luck. The behavioural half proper is `QaPlannedStatusReachabilityTest` - a
| job in each accepted status reaching `done`. This file carries the
| dataset-driven halves that bound the shipped list:
|
|  - the cases below take their dataset **from the authority itself**, so a
|    status added to the policy is immediately demanded of the planner rather
|    than quietly accepted by one side only;
|  - deny by default on the other side: every status outside the list is
|    refused by the gate *and* by a named run.
|
| **What is deliberately not here any more.** Step 13b proved "the planner
| keeps no copy of the list" by searching the planner's source for the
| statuses in single quotes, and `qa` evaded that with a second literal copy
| in double quotes while all eleven relevant tests stayed green. The scan is
| deleted rather than widened (plan section 18.1): a textual search tries to
| prove a negative about text, and the set of texts meaning the same thing is
| unbounded. What replaces it is the moved key in
| packages/kernel/tests/Feature/TenantStatusPolicyTest.php, which never looks
| at how the dependent is written.
|
| No engine: the master is the suite's in-memory SQLite and nothing here
| opens a tenant connection.
*/

require_once __DIR__.'/../../../Support/migration-run-fixture.php';

uses(TestCase::class);

beforeEach(function () {
    masterMappingMigrate();

    config()->set('schema.backups.available', false);
});

/*
|--------------------------------------------------------------------------
| Both sides of the one list, with the dataset read from the list itself
|--------------------------------------------------------------------------
*/

it('plans a named run and permits a migrating entry for every status the authority accepts', function () {
    $set = runFixtureSet();
    runFixturePublish($set);

    $server = masterMappingServer('authority-server');

    foreach (app(TenantStatusPolicy::class)->accepted(TenantConnectionPurpose::Migrating) as $index => $status) {
        // Short, distinct stems: the fixture derives the database name from
        // the stem and truncates it, so two long ones collide on the unique
        // (server_id, database_name) index rather than testing anything.
        $tenant = runFixtureTenant('auth-'.$index, $server, $status, 0, 10);

        $result = app(PlanMigrationRunHandler::class)
            ->handle(new PlanMigrationRun(set: $set, tenantIds: [$tenant]));

        expect($result->tenantIds())->toBe([$tenant])
            // The other side of the same list: a plan the door would refuse
            // is the whole of BUG-1.
            ->and(app(TenantStatusPolicy::class)->permits(TenantConnectionPurpose::Migrating, $status))->toBeTrue();
    }
});

it('refuses a named run and a migrating entry for every status outside the authority', function (string $status) {
    $set = runFixtureSet();
    runFixturePublish($set);

    $server = masterMappingServer('authority-server');
    $tenant = runFixtureTenant('auth-x', $server, $status, 0, 10);

    $policy = app(TenantStatusPolicy::class);

    expect($policy->permits(TenantConnectionPurpose::Migrating, $status))->toBeFalse()
        ->and($policy->permits(TenantConnectionPurpose::Serving, $status))->toBeFalse()
        ->and(fn () => app(PlanMigrationRunHandler::class)->handle(
            new PlanMigrationRun(set: $set, tenantIds: [$tenant])
        ))->toThrow(MigrationRunRefused::class, $status);
})->with([
    // Deliberately excluded: a worker killed mid-offline-run leaves a tenant
    // no later run can enter, and the operator's path is a repair plus a
    // status correction in the master (plan 16.1, recorded not scheduled).
    'migrating' => ['migrating'],
    'suspended' => ['suspended'],
    'moving' => ['moving'],
    'restoring' => ['restoring'],
    'archived' => ['archived'],
    'an unrecognised status' => ['something-nobody-has-shipped-yet'],
]);

it('keeps the serving purpose at exactly active', function () {
    // The waiver is an exception, not a principle: if this list ever grows,
    // the request path serves a shop somebody stopped. The behavioural half
    // is TenancyResolutionTest's non-active refusal dataset, which now holds
    // `read_only` and `failed` for this reason.
    $policy = app(TenantStatusPolicy::class);

    expect($policy->accepted(TenantConnectionPurpose::Serving))->toBe(['active'])
        ->and($policy->permits(TenantConnectionPurpose::Serving, 'active'))->toBeTrue();

    foreach ($policy->accepted(TenantConnectionPurpose::Migrating) as $status) {
        if ($status === 'active') {
            continue;
        }

        expect($policy->permits(TenantConnectionPurpose::Serving, $status))->toBeFalse();
    }
});
