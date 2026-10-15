<?php

use Flexipos\Kernel\Schema\Features\RunTenantMigrations\RunTenantMigrations;
use Flexipos\Kernel\Schema\Features\RunTenantMigrations\RunTenantMigrationsHandler;
use Flexipos\Kernel\Schema\Features\RunTenantMigrations\RunTenantMigrationsResult;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-008 step 13f: a run does not report itself finished while work is in flight
|--------------------------------------------------------------------------
|
| Plan section 19.2. `finish()` counted the `pending` and `running` jobs into
| the run row and then wrote `completed` or `completed_with_failures` over
| them - the status depended only on whether anything had failed. So a run
| could be terminal with a job still held by a worker under an unexpired
| lease, and its own row said so in the counters while its status denied it.
| That is the "run whose state lies" that plan section 17.2 attached two
| conditions to prevent, and neither had been built.
|
| **The input is one `done` job and one job that is not terminal**, in the two
| shapes that are reachable:
|
|  - a `pending` job the claim will not offer, because its attempts are at
|    D-20's cap - the loop ends, the run is not finished;
|  - a `running` job held by another worker whose lease has **not** expired,
|    which is the in-flight worker the condition is actually about. An
|    expired lease is claimable and the loop takes it, so it is not this.
|
| The control beside both is a run whose every job is terminal: unchanged
| behaviour, still `completed`, still `finished_at`. A third case re-invokes
| the finishing path on that completed run with the clock moved on, and
| asserts nothing moved - the moved clock is what makes "unchanged" an
| assertion rather than two equal timestamps.
|
| AC-44's own cases are unaffected and are this file's wider control: in both
| of them every job is terminal. No engine: the master is the suite's
| in-memory SQLite and no tenant database is opened, because the loop claims
| nothing in any case here.
*/

require_once __DIR__.'/../../../Support/migrate-tenant-fixture.php';

uses(TestCase::class);

beforeEach(function () {
    migrateFixtureBoot();

    $server = migrateFixtureServer();

    migrateFixtureTenant('tenant-a', $server);
    migrateFixtureTenant('tenant-b', $server);
});

afterEach(function () {
    migrateFixtureTearDown();
});

/**
 * A run with one finished job and one job in the given state.
 *
 * @param  array<string, mixed>  $overrides  on the second job
 */
function terminalStateRun(array $overrides): string
{
    $runId = migrateFixtureRun();
    $server = migrateFixtureId('server-one');

    migrateFixtureClaimedJob(
        $runId,
        'JOB0000000000000000000001',
        migrateFixtureId('tenant-a'),
        $server,
        6,
        overrides: ['status' => 'done', 'finished_at' => '2026-10-15 09:05:00.000'],
    );

    migrateFixtureClaimedJob(
        $runId,
        'JOB0000000000000000000002',
        migrateFixtureId('tenant-b'),
        $server,
        6,
        overrides: $overrides,
    );

    return $runId;
}

/**
 * Resume that run in this process.
 */
function terminalStateResume(string $runId): RunTenantMigrationsResult
{
    return app(RunTenantMigrationsHandler::class)->handle(
        new RunTenantMigrations(set: runFixtureSet(), runId: $runId, workerId: 'worker-one')
    );
}

/*
|--------------------------------------------------------------------------
| The hole: a terminal status written over a job that has not finished
|--------------------------------------------------------------------------
*/

it('keeps the run running when a pending job is still outstanding', function () {
    // Pending at the attempt cap: the claim will not offer it, so the loop
    // ends with the job still pending. Before step 13f this run was written
    // `completed` with `pending = 1` in its own row.
    $runId = terminalStateRun([
        'status' => 'pending',
        'attempts' => 3,
        'claimed_by' => null,
        'claimed_at' => null,
        'lock_expires_at' => null,
        'started_at' => null,
    ]);

    $result = terminalStateResume($runId);

    expect($result->status)->toBe('running')
        ->and($result->exitCode)->toBe(1)
        ->and($result->outstanding)->toBe(1);

    $run = migrateFixtureRunRow($runId);

    expect((string) $run['status'])->toBe('running')
        ->and($run['finished_at'])->toBeNull()
        // The counters are still written: they are a true count either way,
        // and the report needs them.
        ->and((int) $run['total'])->toBe(2)
        ->and((int) $run['done'])->toBe(1)
        ->and((int) $run['pending'])->toBe(1);
});

it('keeps the run running when another worker still holds a job under a live lease', function () {
    // The shape the residual is about: a queue worker is migrating this
    // tenant right now. Its lease expires at 09:30 and the clock is stopped
    // at 09:00, so the claim correctly declines to take the job - and a run
    // that called itself completed here would be reporting a migration that
    // is still running as finished.
    $runId = terminalStateRun([
        'status' => 'running',
        'claimed_by' => 'worker-two',
        'lock_expires_at' => '2026-10-15 09:30:00.000',
    ]);

    $result = terminalStateResume($runId);

    expect($result->status)->toBe('running')
        ->and($result->exitCode)->toBe(1)
        ->and($result->outstanding)->toBe(1);

    $run = migrateFixtureRunRow($runId);

    expect((string) $run['status'])->toBe('running')
        ->and($run['finished_at'])->toBeNull()
        ->and((int) $run['running'])->toBe(1)
        ->and((int) $run['done'])->toBe(1);
});

/*
|--------------------------------------------------------------------------
| The control, and the second invocation that must change nothing
|--------------------------------------------------------------------------
*/

it('still completes a run whose every job is terminal', function () {
    $runId = terminalStateRun([
        'status' => 'failed',
        'finished_at' => '2026-10-15 09:06:00.000',
        'error_class' => 'RuntimeException',
        'error_message' => 'the fixture failed this one',
    ]);

    $result = terminalStateResume($runId);

    expect($result->status)->toBe('completed_with_failures')
        ->and($result->exitCode)->toBe(1)
        ->and($result->outstanding)->toBe(0);

    $run = migrateFixtureRunRow($runId);

    expect((string) $run['status'])->toBe('completed_with_failures')
        ->and($run['finished_at'])->not->toBeNull()
        ->and((int) $run['failed'])->toBe(1)
        ->and((int) $run['done'])->toBe(1);
});

it('writes no new status and no new finished_at when the run is already terminal', function () {
    $runId = terminalStateRun([
        'status' => 'done',
        'finished_at' => '2026-10-15 09:06:00.000',
    ]);

    $first = terminalStateResume($runId);

    expect($first->status)->toBe('completed')
        ->and($first->exitCode)->toBe(0);

    $finishedAt = (string) migrateFixtureRunRow($runId)['finished_at'];

    // The clock moves, so a second write of `finished_at` would be visible.
    // Without this the case would pass on a handler that rewrites the field
    // with an identical stopped timestamp.
    app(RunnerClock::class)->advanceMinutes(17);

    $second = terminalStateResume($runId);

    expect($second->status)->toBe('completed')
        ->and($second->exitCode)->toBe(0)
        ->and($second->outstanding)->toBe(0);

    $run = migrateFixtureRunRow($runId);

    expect((string) $run['status'])->toBe('completed')
        ->and((string) $run['finished_at'])->toBe($finishedAt);
});

/*
|--------------------------------------------------------------------------
| What the operator is told
|--------------------------------------------------------------------------
*/

it('names the run and the invocation that will finish it, and exits non-zero', function () {
    $runId = terminalStateRun([
        'status' => 'running',
        'claimed_by' => 'worker-two',
        'lock_expires_at' => '2026-10-15 09:30:00.000',
    ]);

    expect(Artisan::call('tenant:migrate', ['--run' => $runId]))->toBe(1);

    $output = Artisan::output();

    expect($output)->toContain($runId)
        ->and($output)->toContain('tenant:migrate --run='.$runId)
        // The count, so the operator knows whether to wait or to look.
        ->and($output)->toContain('1');
});

/*
|--------------------------------------------------------------------------
| The recorded red
|--------------------------------------------------------------------------
|
| Written before the fix and run against it (MEASURED, 2026-10-15): all five
| cases failed, the first two with `completed` where `running` was expected -
| a run row carrying `pending = 1` and the status `completed` at the same
| time. That is the hole plan section 19.2 names, and it is the reason the
| first two cases here are the step rather than a regression guard.
|
| **No existing case changed meaning.** The whole RunTenantMigrations and
| DispatchMigrationRun suites were green before and after, so nothing in the
| branch was asserting the defect - in AC-44's two runs every job is
| terminal, which is condition 1's control, and the dispatch path never
| reaches finish().
|
| **One production change beyond the two the step asked for, and it is
| declared.** `tenant:migrate --run=<id>` was refused by the command's own
| "name --tenant or --all" guard, so both the instruction this step prints
| and the one dispatch() already printed named an invocation that could not
| run. The guard now accepts a run id, which names the work as precisely as
| --tenant does and plans nothing. The case that asserts the refusal for a
| bare invocation is unchanged and still green.
*/
