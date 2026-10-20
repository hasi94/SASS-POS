<?php

use Flexipos\Kernel\Schema\Features\MigrateTenant\MigrateTenantQueueJob;
use Flexipos\Kernel\Schema\Features\RunTenantMigrations\RunTenantMigrations;
use Flexipos\Kernel\Schema\Features\RunTenantMigrations\RunTenantMigrationsHandler;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| AC-41: when the work is dispatched
|--------------------------------------------------------------------------
|
| T-008 BUG-8. MigrateTenantQueueJob carried the right payload on the right
| queue and **nothing ever dispatched it**: `tenant:migrate` executed every
| job in its own process, so the step the criterion starts from - "when the
| work is dispatched" - had no code path at all. The payload half was tested;
| the dispatch half did not exist.
|
| What is asserted here is one queue job per planned tenant, on the queue the
| host configures, carrying that tenant's job row - and that dispatching
| migrates nothing by itself. The payload's contents stay
| MigrateTenantQueueJobTest's subject, and the control that keeps this file
| honest is the in-process run beside it: the same fixture, no --queue, and
| nothing dispatched.
|
| Bus::fake() rather than a real worker: what AC-41 is about is what is handed
| over, and running the jobs here would be the in-process path again under
| another name.
*/

require_once __DIR__.'/../../../Support/migrate-tenant-fixture.php';

uses(TestCase::class);

beforeEach(function () {
    migrateFixtureBoot();

    $server = migrateFixtureServer();

    runFixturePublish(runFixtureSet());

    migrateFixtureTenant('tenant-a', $server, ['size_bytes' => 1024]);
    migrateFixtureTenant('tenant-b', $server, ['size_bytes' => 2048]);
});

afterEach(function () {
    migrateFixtureTearDown();
});

/**
 * The job rows a run holds, as id => status.
 *
 * @return array<string, string>
 */
function dispatchJobStatuses(string $runId): array
{
    $statuses = [];

    foreach (DB::connection('master')->table('tenant_migration_jobs')->where('run_id', $runId)->get() as $row) {
        $job = (array) $row;
        $statuses[(string) $job['id']] = (string) $job['status'];
    }

    return $statuses;
}

it('dispatches one queue job per planned tenant, on the configured queue', function () {
    Bus::fake();

    $dispatched = app(RunTenantMigrationsHandler::class)->dispatch(
        new RunTenantMigrations(set: runFixtureSet(), workerId: 'worker-one'),
        (string) config('schema.queue')
    );

    expect($dispatched->jobIds)->toHaveCount(2)
        ->and($dispatched->queue)->toBe('migrations')
        ->and($dispatched->runId)->not->toBe('')
        ->and($dispatched->targetVersion)->toBe(tenantMigrationFileCount());

    Bus::assertDispatchedTimes(MigrateTenantQueueJob::class, 2);

    foreach ($dispatched->jobIds as $jobId) {
        Bus::assertDispatched(
            MigrateTenantQueueJob::class,
            fn (MigrateTenantQueueJob $job): bool => $job->jobId === $jobId
                && $job->runId === $dispatched->runId
                && $job->workerId === 'worker-one'
                && $job->queue === 'migrations'
        );
    }

    // One job per tenant, and the tenants are the two the plan selected.
    Bus::assertDispatched(
        MigrateTenantQueueJob::class,
        fn (MigrateTenantQueueJob $job): bool => $job->tenantId === migrateFixtureId('tenant-a')
    );
    Bus::assertDispatched(
        MigrateTenantQueueJob::class,
        fn (MigrateTenantQueueJob $job): bool => $job->tenantId === migrateFixtureId('tenant-b')
    );
});

it('migrates nothing by dispatching, and leaves every job claimable', function () {
    Bus::fake();

    $dispatched = app(RunTenantMigrationsHandler::class)->dispatch(
        new RunTenantMigrations(set: runFixtureSet(), workerId: 'worker-one'),
        (string) config('schema.queue')
    );

    // The work was handed over, not done: the worker that picks the job up
    // claims it the same way an in-process run does.
    expect(array_values(dispatchJobStatuses($dispatched->runId)))->toBe(['pending', 'pending'])
        ->and(migrateFixtureApplier()->calls)->toBe([])
        ->and((int) migrateFixtureMappingRow(migrateFixtureId('tenant-a'))['schema_version'])->toBe(0);
});

it('dispatches nothing when the run is executed in this process', function () {
    // The control. Without it, "two jobs were dispatched" is also what a
    // Bus::fake() that intercepts the in-process path would report.
    Bus::fake();

    $result = app(RunTenantMigrationsHandler::class)->handle(
        new RunTenantMigrations(set: runFixtureSet(), workerId: 'worker-one')
    );

    expect($result->counts['done'])->toBe(2);

    Bus::assertNothingDispatched();
});

it('dispatches from the command, and says what has not happened yet', function () {
    Bus::fake();

    expect(Artisan::call('tenant:migrate', ['--all' => true, '--queue' => true]))->toBe(0);

    $output = Artisan::output();

    // An operator told only "dispatched" would read it as "done" and would
    // have no way to close the run, so the second line is asserted too.
    expect($output)->toContain('2 job(s) dispatched on the [migrations] queue')
        ->and($output)->toContain('Nothing has been migrated yet');

    Bus::assertDispatchedTimes(MigrateTenantQueueJob::class, 2);
});
