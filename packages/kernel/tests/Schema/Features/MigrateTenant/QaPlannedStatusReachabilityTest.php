<?php

use Flexipos\Kernel\Schema\Features\MigrateTenant\MigrateTenant;
use Flexipos\Kernel\Schema\Features\MigrateTenant\MigrateTenantHandler;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| QA, T-008: can the runner migrate every status it is willing to plan?
|--------------------------------------------------------------------------
|
| AC-40 plans `active` and `read_only` in a bulk run; AC-51 plans `provisioning`
| and `failed` as well when one tenant is named (D-11). A tenant is only
| migrated if the job that was planned for it can then open its database, and
| the only door is Tenancy::enter() for the Migrating purpose. That door
| refuses every status but `active` (Tenancy step 4, TenantMigrationTarget::
| isActive).
|
| Each row below plans nothing and claims nothing differently from the
| shipped handler tests: it takes a tenant in a status the planner accepts,
| hands the handler a claimed job for it, and asks the one question the
| existing cases never ask - did the job end `done`?
|
| No engine is involved: the master is the suite's in-memory SQLite and the
| tenant is a throwaway SQLite file behind the fake migrator, exactly as in
| MigrateTenantHandlerTest.
*/

require_once __DIR__.'/../../../Support/migrate-tenant-fixture.php';

uses(TestCase::class);

beforeEach(function () {
    migrateFixtureBoot();
    migrateFixtureServer();
    migrateFixtureRun();
});

afterEach(function () {
    migrateFixtureTearDown();
});

it('migrates a tenant in each status the planner accepts', function (string $status) {
    $tenant = migrateFixtureTenant('tenant-a', migrateFixtureId('server-one'), ['status' => $status]);
    $job = migrateFixtureClaimedJob(migrateFixtureRunId(), 'JOB0000000000000000000001', $tenant, migrateFixtureId('server-one'), 6);

    $result = app(MigrateTenantHandler::class)->handle(new MigrateTenant($job, 'worker-one', runFixtureSet()));

    // The failure message is part of the assertion: if it is not a status
    // refusal the premise of this case is wrong and the case says so.
    expect((string) $result->errorMessage)->toBe('')
        ->and($result->succeeded)->toBeTrue()
        ->and((string) migrateFixtureJobRow($job)['status'])->toBe('done');
})->with([
    'active (the control: the one status the door accepts)' => ['active'],
    'read_only (AC-40, bulk)' => ['read_only'],
    'provisioning (AC-51, named)' => ['provisioning'],
    'failed (AC-51, named, the retry after a failed offline migration)' => ['failed'],
]);
