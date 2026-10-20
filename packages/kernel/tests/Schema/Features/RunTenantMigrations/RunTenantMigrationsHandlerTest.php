<?php

use Flexipos\Kernel\Schema\Exceptions\RunnerLoopRefused;
use Flexipos\Kernel\Schema\Features\RunTenantMigrations\RunTenantMigrations;
use Flexipos\Kernel\Schema\Features\RunTenantMigrations\RunTenantMigrationsHandler;
use Flexipos\Kernel\Schema\Features\RunTenantMigrations\RunTenantMigrationsResult;
use Flexipos\Kernel\Tenancy\Exceptions\NoTenantResolved;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| A whole run, in one worker process
|--------------------------------------------------------------------------
|
| T-008 step 11. This is where the criteria that are about a **run** rather
| than about a job are measured: AC-44's two outcomes and their exit codes,
| AC-42's two tenants in one process, and AC-46's "nothing ran twice".
|
| Three tenants on the stand-in, each a real SQLite file, with the migrator
| faked behind the TenantMigrationApplier seam. No engine: the hold is on and
| the engine half of these criteria - FX-1's table existing in A, B and C -
| is [gated] and stays NOT VERIFIED behind G-1.
|
| Red is proven by omitting the mechanism. Nothing here issues a destructive
| statement.
|
*/

require_once __DIR__.'/../../../Support/migrate-tenant-fixture.php';

uses(TestCase::class);

beforeEach(function () {
    migrateFixtureBoot();

    $server = migrateFixtureServer();

    runFixturePublish(runFixtureSet());

    // Three tenants, ascending size, all behind the baseline: the dataset the
    // planner orders by and the run works through.
    migrateFixtureTenant('tenant-a', $server, ['size_bytes' => 1024]);
    migrateFixtureTenant('tenant-b', $server, ['size_bytes' => 2048]);
    migrateFixtureTenant('tenant-c', $server, ['size_bytes' => 3072]);
});

afterEach(function () {
    migrateFixtureTearDown();
});

/**
 * One bulk invocation of the runner.
 */
function runAll(string $worker = 'worker-one'): RunTenantMigrationsResult
{
    return app(RunTenantMigrationsHandler::class)->handle(
        new RunTenantMigrations(set: runFixtureSet(), workerId: $worker)
    );
}

/**
 * Tell the fake migrator to fail on one tenant's database, as FX-2 does.
 */
function runFailOn(FakeTenantMigrationApplier $applier, string $stem): void
{
    $applier->onMigration = function (string $database, string $migration) use ($stem): void {
        if (str_ends_with($database, $stem.'.sqlite')) {
            throw new RuntimeException('the fixture migration was told to fail on ['.$database.']');
        }
    };
}

/*
|--------------------------------------------------------------------------
| AC-44 - one tenant fails, the rest of the run does not
|--------------------------------------------------------------------------
*/

it('completes the healthy tenants, fails the one that threw, and exits non-zero', function () {
    runFailOn(migrateFixtureApplier(), 'tenant-c');

    $result = runAll();

    expect($result->status)->toBe('completed_with_failures')
        ->and($result->exitCode)->toBe(1)
        // Planned in ascending size, and executed in that order.
        ->and($result->tenantIds())->toBe([migrateFixtureId('tenant-a'), migrateFixtureId('tenant-b'), migrateFixtureId('tenant-c')])
        ->and($result->counts['done'])->toBe(2)
        ->and($result->counts['failed'])->toBe(1);

    foreach ([migrateFixtureId('tenant-a'), migrateFixtureId('tenant-b')] as $healthy) {
        expect((int) migrateFixtureMappingRow($healthy)['schema_version'])->toBe(tenantMigrationFileCount())
            ->and(migrateFixtureMappingRow($healthy)['last_migration_error'])->toBeNull();
    }

    $failed = migrateFixtureMappingRow(migrateFixtureId('tenant-c'));

    // Unchanged, which is the half of AC-44 a rollback could not give.
    expect((int) $failed['schema_version'])->toBe(0)
        ->and((string) $failed['last_migration_error'])->toContain('told to fail');

    $row = (array) DB::connection('master')->table('tenant_migration_jobs')
        ->where('tenant_id', migrateFixtureId('tenant-c'))->first();

    expect((string) $row['status'])->toBe('failed')
        ->and((string) $row['error_class'])->toBe(RuntimeException::class)
        ->and((string) $row['error_migration'])->not->toBe('')
        ->and((string) $row['error_message'])->toContain('told to fail');

    expect((string) migrateFixtureRunRow($result->runId)['status'])->toBe('completed_with_failures');
});

it('completes and exits zero when every tenant succeeds', function () {
    // The control. Without it, "the run ends completed_with_failures" is also
    // true of a handler that never computes `completed`.
    $result = runAll();

    expect($result->status)->toBe('completed')
        ->and($result->exitCode)->toBe(0)
        ->and($result->counts['failed'])->toBe(0)
        ->and($result->counts['done'])->toBe(3)
        ->and((string) migrateFixtureRunRow($result->runId)['status'])->toBe('completed');
});

/*
|--------------------------------------------------------------------------
| AC-42 - two tenants, one worker process
|--------------------------------------------------------------------------
*/

it('gives each job its own tenant database and leaves no tenant resolved behind it', function () {
    $result = runAll();

    $databases = array_map(static fn ($job): string => $job->databaseName, $result->jobs);

    // Each job reported the database its **connection** was using - the fake
    // migrator reads it off the live connection, not off the mapping - so two
    // jobs in one process having two distinct names is a fact about the
    // connection and not about the dataset.
    expect($databases)->toHaveCount(3)
        ->and(array_unique($databases))->toHaveCount(3)
        ->and($databases[0])->toEndWith('tenant-a.sqlite')
        ->and($databases[1])->toEndWith('tenant-b.sqlite');

    // The fake saw three distinct databases too, which is the same fact from
    // inside the migration.
    expect(array_keys(migrateFixtureApplier()->appliedByDatabase))->toHaveCount(3);

    // And the process is back to "no tenant resolved": the T-003/T-007
    // isolation guarantee survives a run of several tenants.
    expect(static fn () => DB::connection('tenant')->select('select 1'))
        ->toThrow(NoTenantResolved::class);
});

/*
|--------------------------------------------------------------------------
| AC-46 - a retry runs the failed tenant and nothing else
|--------------------------------------------------------------------------
*/

it('re-runs only the failed tenant and leaves the finished ones untouched', function () {
    runFailOn(migrateFixtureApplier(), 'tenant-c');

    $first = runAll();

    expect($first->status)->toBe('completed_with_failures');

    $appliedToA = migrateFixtureApplier()->appliedByDatabase[migrateFixtureDatabasePath('tenant-a')];
    $appliedToB = migrateFixtureApplier()->appliedByDatabase[migrateFixtureDatabasePath('tenant-b')];

    // The cause is removed, and the same run is resumed with a retry.
    migrateFixtureApplier()->onMigration = null;

    $second = app(RunTenantMigrationsHandler::class)->handle(new RunTenantMigrations(
        set: runFixtureSet(),
        runId: $first->runId,
        retryFailed: true,
        workerId: 'worker-one',
    ));

    expect($second->runId)->toBe($first->runId)
        ->and($second->status)->toBe('completed')
        ->and($second->exitCode)->toBe(0)
        // Only C was executed the second time.
        ->and($second->tenantIds())->toBe([migrateFixtureId('tenant-c')]);

    // Identical lists, not merely "the table still exists" (plan section 7's
    // mutation note for AC-46): a second application would have appended.
    expect(migrateFixtureApplier()->appliedByDatabase[migrateFixtureDatabasePath('tenant-a')])->toBe($appliedToA)
        ->and(migrateFixtureApplier()->appliedByDatabase[migrateFixtureDatabasePath('tenant-b')])->toBe($appliedToB)
        ->and((int) migrateFixtureMappingRow(migrateFixtureId('tenant-c'))['schema_version'])->toBe(tenantMigrationFileCount())
        ->and(migrateFixtureMappingRow(migrateFixtureId('tenant-c'))['last_migration_error'])->toBeNull();
});

it('plans nothing when it is resuming a run', function () {
    // A resumption that re-planned could write a second job row for a tenant
    // whose first is still running. One run row, three job rows, after two
    // invocations.
    runFailOn(migrateFixtureApplier(), 'tenant-c');

    $first = runAll();

    migrateFixtureApplier()->onMigration = null;

    app(RunTenantMigrationsHandler::class)->handle(new RunTenantMigrations(
        set: runFixtureSet(),
        runId: $first->runId,
        retryFailed: true,
    ));

    expect(runFixtureRunRows())->toHaveCount(1)
        ->and(runFixtureJobRows())->toHaveCount(3);
});

it('refuses to resume a run that does not exist', function () {
    expect(fn () => app(RunTenantMigrationsHandler::class)->handle(new RunTenantMigrations(
        set: runFixtureSet(),
        runId: 'RUN0000000000000000000099',
    )))->toThrow(RunnerLoopRefused::class, 'there is nothing to resume');
});

/*
|--------------------------------------------------------------------------
| The loop refuses rather than spins
|--------------------------------------------------------------------------
*/

it('stops when a job comes back claimable instead of looping over it', function () {
    // The input: while the first tenant is migrating, a second worker takes
    // the job over and then dies - its lease is already in the past. The
    // completing statement's re-check refuses to write (the holder is not us)
    // and the job is left `running` with an expired lease, which is exactly
    // the state ClaimMigrationJobHandler hands back to the next worker. The
    // loop would otherwise do the same job for ever. A runner with an
    // iteration bound would have stopped early and said nothing; this one
    // names the job.
    migrateFixtureApplier()->onMigration = function (string $database, string $migration): void {
        DB::connection('master')->table('tenant_migration_jobs')
            ->where('status', 'running')
            ->update([
                'claimed_by' => 'worker-two',
                'lock_expires_at' => '2026-10-15 08:00:00.000',
            ]);
    };

    expect(fn () => runAll())->toThrow(RunnerLoopRefused::class, 'was claimed twice in one invocation');
});

/*
|--------------------------------------------------------------------------
| The entry point - tenant:migrate
|--------------------------------------------------------------------------
|
| The command is in the slice because the exit code is AC-44's own content,
| and a command that decided for itself what a failed run is would be a second
| rule about it.
|
*/

it('refuses to do anything when neither a tenant nor --all is named', function () {
    // A bulk run touches every eligible tenant on the platform. An invocation
    // that did that because an argument was forgotten is the accident this
    // refusal exists for.
    expect(Artisan::call('tenant:migrate'))->toBe(1)
        ->and(Artisan::output())->toContain('--all');

    expect(runFixtureRunRows())->toBe([]);
});

it('exits zero for a run in which every tenant succeeded', function () {
    expect(Artisan::call('tenant:migrate', ['--all' => true]))->toBe(0);

    $run = runFixtureRunRows()[0];

    expect((string) $run['status'])->toBe('completed');
});

it('exits non-zero for a run that ended with failures', function () {
    runFailOn(migrateFixtureApplier(), 'tenant-c');

    expect(Artisan::call('tenant:migrate', ['--all' => true]))->toBe(1);

    $run = runFixtureRunRows()[0];

    expect((string) $run['status'])->toBe('completed_with_failures');
});
