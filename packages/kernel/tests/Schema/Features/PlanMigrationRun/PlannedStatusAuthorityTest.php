<?php

use Flexipos\Kernel\Schema\Exceptions\MigrationRunRefused;
use Flexipos\Kernel\Schema\Features\PlanMigrationRun\PlanMigrationRun;
use Flexipos\Kernel\Schema\Features\PlanMigrationRun\PlanMigrationRunHandler;
use Flexipos\Kernel\Tenancy\TenantConnectionPurpose;
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
| The fix is one list, `TenantConnectionPurpose::acceptedStatuses()`, with
| the planner's sets derived from it. **Two teeth are required and neither
| is sufficient**: exact equality passes on two lists that are equally
| wrong, and the behavioural half passes on two lists that agree by luck.
| The behavioural half proper is `QaPlannedStatusReachabilityTest` - a job
| in each accepted status reaching `done`. This file carries the structural
| tooth and the two gate-side halves that bound it:
|
|  - the planner's sets *are* the purpose's, by exact equality and by
|    subtraction, so neither can drift;
|  - the cases below take their dataset **from the authority itself**, so a
|    status added to the enum is immediately demanded of the planner rather
|    than quietly accepted by one side only;
|  - deny by default on the other side: every status outside the list is
|    refused by the gate *and* by a named run.
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
| The structural tooth: one list, read twice, never written twice
|--------------------------------------------------------------------------
*/

it('derives the planner accepted set from the purpose rather than restating it', function () {
    expect(PlanMigrationRunHandler::NAMED_ACCEPTED)
        ->toBe(TenantConnectionPurpose::Migrating->acceptedStatuses());
});

it('derives the bulk set by subtracting the named-only statuses from the accepted set', function () {
    $bulk = PlanMigrationRunHandler::bulkStatuses();

    expect($bulk)->toBe(array_values(array_diff(
        PlanMigrationRunHandler::NAMED_ACCEPTED,
        PlanMigrationRunHandler::NAMED_ONLY_STATUSES,
    )))
        // A subset, and a proper one: a bulk run must not reach a status only
        // an operator naming a tenant may reach.
        ->and(array_diff($bulk, PlanMigrationRunHandler::NAMED_ACCEPTED))->toBe([])
        ->and($bulk)->not->toBe(PlanMigrationRunHandler::NAMED_ACCEPTED);
});

it('writes no accepted status of its own anywhere in the planner source', function () {
    // The tooth that actually moves the key: equality holds just as well for
    // two literal arrays that happen to match today. The planner may name the
    // statuses it *subtracts* - that split is its own rule - but the statuses
    // only the gate decides must appear nowhere in its source, or there is a
    // second copy for the next edit to desynchronise.
    $source = (string) file_get_contents(
        (string) (new ReflectionClass(PlanMigrationRunHandler::class))->getFileName()
    );

    $gateOnly = array_values(array_diff(
        TenantConnectionPurpose::Migrating->acceptedStatuses(),
        PlanMigrationRunHandler::NAMED_ONLY_STATUSES,
    ));

    // The scan asserts its own subject is non-empty, so a derivation that
    // silently returned [] could not pass this by scanning nothing.
    expect($gateOnly)->not->toBe([])
        ->and($source)->not->toBe('');

    foreach ($gateOnly as $status) {
        expect($source)->not->toContain("'".$status."'");
    }
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

    foreach (TenantConnectionPurpose::Migrating->acceptedStatuses() as $index => $status) {
        // Short, distinct stems: the fixture derives the database name from
        // the stem and truncates it, so two long ones collide on the unique
        // (server_id, database_name) index rather than testing anything.
        $tenant = runFixtureTenant('auth-'.$index, $server, $status, 0, 10);

        $result = app(PlanMigrationRunHandler::class)
            ->handle(new PlanMigrationRun(set: $set, tenantIds: [$tenant]));

        expect($result->tenantIds())->toBe([$tenant])
            // The other side of the same list: a plan the door would refuse
            // is the whole of BUG-1.
            ->and(TenantConnectionPurpose::Migrating->permits($status))->toBeTrue();
    }
});

it('refuses a named run and a migrating entry for every status outside the authority', function (string $status) {
    $set = runFixtureSet();
    runFixturePublish($set);

    $server = masterMappingServer('authority-server');
    $tenant = runFixtureTenant('auth-x', $server, $status, 0, 10);

    expect(TenantConnectionPurpose::Migrating->permits($status))->toBeFalse()
        ->and(TenantConnectionPurpose::Serving->permits($status))->toBeFalse()
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
    expect(TenantConnectionPurpose::Serving->acceptedStatuses())->toBe(['active'])
        ->and(TenantConnectionPurpose::Serving->permits('active'))->toBeTrue();

    foreach (TenantConnectionPurpose::Migrating->acceptedStatuses() as $status) {
        if ($status === 'active') {
            continue;
        }

        expect(TenantConnectionPurpose::Serving->permits($status))->toBeFalse();
    }
});
