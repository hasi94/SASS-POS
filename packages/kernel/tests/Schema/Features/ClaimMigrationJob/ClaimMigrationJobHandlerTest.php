<?php

use Flexipos\Kernel\Schema\Features\ClaimMigrationJob\ClaimedJob;
use Flexipos\Kernel\Schema\Features\ClaimMigrationJob\ClaimMigrationJob;
use Flexipos\Kernel\Schema\Features\ClaimMigrationJob\ClaimMigrationJobHandler;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| One statement grants the claim
|--------------------------------------------------------------------------
|
| T-008 step 10, the half that needs no engine: AC-47, AC-48's single-statement
| property, and AC-50's cap.
|
| **The split, and what is not here.** The claim is an UPDATE on a master row,
| so it is proven on the suite SQLite master and the red phase is a mutation of
| the real statement - the plan permits that mutation because the statement is
| not destructive and the resource is the suite own (section 11.5, step 10).
| What is **not** here is the half that needs two database sessions: AC-48's
| actual race, AC-49's advisory lock and AC-65. A lock is re-entrant inside one
| session, so a single-process test of it would assert the opposite of the
| criterion. Those stay **NOT VERIFIED** behind ENGINE_HOLD and gate G-1, and
| no test in this file is named as if it covered them.
|
| The mutation, MEASURED 2026-10-15: removing `attempts < :max` and the status
| term from the claim WHERE. Three cases below go red on it, and each names in
| its own body which term it is about, so a later reader can tell which half of
| the mutation a failure belongs to.
|
| The clock is fixed and moved by hand, because AC-47 wants an expired claim
| and an unexpired one in **one dataset** and the only alternative is sleeping
| for the configured thirty minutes.
|
*/

require_once __DIR__.'/../../../Support/migration-run-fixture.php';

uses(TestCase::class);

/**
 * The clock every case in this file shares, stopped so the lease is a known
 * distance away.
 */
function claimClock(): RunnerClock
{
    return app(RunnerClock::class);
}

beforeEach(function () {
    masterMappingMigrate();

    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-10-15 09:00:00.000'));

    config()->set('schema.max_attempts', 3);
    config()->set('schema.claim_ttl_minutes', 30);
    config()->set('schema.per_server_limit', 2);
});

/**
 * One run row, written directly: this slice is about the job table, and
 * planning has its own test.
 */
function claimFixtureRun(string $runId = 'RUN0000000000000000000001'): string
{
    DB::connection('master')->table('tenant_migration_runs')->insert([
        'id' => $runId,
        'target_version' => 7,
        'mode' => 'bulk',
        'concurrency' => 1,
        'status' => 'planned',
        'total' => 0,
        'pending' => 0,
        'created_at' => '2026-10-15 09:00:00.000',
        'updated_at' => '2026-10-15 09:00:00.000',
    ]);

    return $runId;
}

/**
 * One job row, in whatever state the case is about.
 *
 * @param  array<string, mixed>  $overrides
 */
function claimFixtureJob(string $runId, string $jobId, string $serverId = 'SERVERA00000000000000000A', array $overrides = []): string
{
    DB::connection('master')->table('tenant_migration_jobs')->insert(array_merge([
        'id' => $jobId,
        'run_id' => $runId,
        'tenant_id' => str_pad($jobId, 26, 'T'),
        'server_id' => $serverId,
        'from_version' => 6,
        'to_version' => 7,
        'status' => 'pending',
        'attempts' => 0,
    ], $overrides));

    return $jobId;
}

/**
 * Ask for a job as one worker.
 */
function claimAs(string $worker, string $runId = 'RUN0000000000000000000001'): ?ClaimedJob
{
    return app(ClaimMigrationJobHandler::class)->handle(new ClaimMigrationJob($runId, $worker));
}

/**
 * One job row, as an array.
 *
 * @return array<string, mixed>
 */
function claimJobRow(string $jobId): array
{
    return (array) DB::connection('master')->table('tenant_migration_jobs')->where('id', $jobId)->first();
}

/*
|--------------------------------------------------------------------------
| AC-48 - exactly one claim succeeds, and the grant is one statement
|--------------------------------------------------------------------------
*/

it('grants one pending job to one worker and nothing to the next', function () {
    $run = claimFixtureRun();
    $job = claimFixtureJob($run, 'JOB0000000000000000000001');

    $first = claimAs('worker-one');
    $second = claimAs('worker-two');

    // The second worker is told it got nothing. Within one process this is the
    // serial form of AC-48; the two-session form is [E] and NOT VERIFIED.
    expect($first)->toBeInstanceOf(ClaimedJob::class)
        ->and($first->jobId)->toBe($job)
        ->and($second)->toBeNull();

    $row = claimJobRow($job);

    expect((string) $row['status'])->toBe('running')
        ->and((string) $row['claimed_by'])->toBe('worker-one')
        ->and((int) $row['attempts'])->toBe(1)
        ->and((string) $row['lock_expires_at'])->toBe('2026-10-15 09:30:00.000');
});

it('grants the claim with exactly one update statement, so the read grants nothing', function () {
    $run = claimFixtureRun();
    claimFixtureJob($run, 'JOB0000000000000000000001');

    $touched = runFixtureConnectionsUsedBy(static fn () => claimAs('worker-one'));

    expect($touched['thrown'])->toBeNull()
        ->and($touched['queried'])->toBe(['master']);

    // AC-48's shape: one statement does the granting. The candidate read and
    // the read-back are selects; exactly one statement in the whole claim is
    // an update, and its affected-row count is the verdict. A select-then-write
    // implementation that granted on the read would show two.
    $updates = [];

    foreach ($touched['sql'] as $sql) {
        if (str_starts_with(strtolower(ltrim($sql)), 'update')) {
            $updates[] = $sql;
        }
    }

    expect($updates)->toHaveCount(1);

    // **And the granting statement re-checks the terms itself.**
    //
    // MEASURED 2026-10-15, and it is why this assertion exists: deleting both
    // terms from the update while leaving them in the candidate read kept all
    // seven cases in this file green. The read masks the update completely in
    // one process, so every behavioural case here is evidence about the *pair*
    // and none of them is evidence about the statement AC-48 is a claim about.
    // The criterion is that the granting statement re-checks status and
    // attempts, so the statement itself is what is asserted - and this is the
    // one case that goes red on the update-only mutation.
    $where = substr($updates[0], (int) strpos($updates[0], ' where '));

    expect($where)->toContain('"attempts" < ?')
        ->and($where)->toContain('"status" = ?')
        ->and($where)->toContain('"lock_expires_at" <= ?');
});

/*
|--------------------------------------------------------------------------
| AC-47 - the lease, both directions, in one dataset
|--------------------------------------------------------------------------
*/

it('refuses a job whose claim has not expired and grants the same job once it has', function () {
    // This case is about the **status term** of the WHERE. Remove it and the
    // first expectation below goes red: a running job with a live lease is
    // handed to a second worker.
    $run = claimFixtureRun();
    $job = claimFixtureJob($run, 'JOB0000000000000000000001');

    expect(claimAs('worker-one'))->toBeInstanceOf(ClaimedJob::class);

    // Still inside the lease: nobody else may have it.
    expect(claimAs('worker-two'))->toBeNull()
        ->and((string) claimJobRow($job)['claimed_by'])->toBe('worker-one');

    // Past the lease: the worker that held it is assumed dead and exactly one
    // other worker takes the job over.
    claimClock()->advanceMinutes(31);

    $retaken = claimAs('worker-two');

    expect($retaken)->toBeInstanceOf(ClaimedJob::class)
        ->and($retaken->jobId)->toBe($job)
        ->and((string) claimJobRow($job)['claimed_by'])->toBe('worker-two')
        // The attempt counter is what makes the retake finite.
        ->and((int) claimJobRow($job)['attempts'])->toBe(2);
});

it('never claims a job that is already done', function () {
    // The other half of the status term, and the control that stops "claimable
    // means any row of this run" from passing the case above.
    $run = claimFixtureRun();
    claimFixtureJob($run, 'JOB0000000000000000000001', overrides: ['status' => 'done', 'attempts' => 1]);

    expect(claimAs('worker-one'))->toBeNull();
});

/*
|--------------------------------------------------------------------------
| D-20 / AC-46 - the attempt cap
|--------------------------------------------------------------------------
*/

it('refuses a job that has reached the configured attempt maximum', function () {
    // This case is about the **attempts < :max** term. Remove it and this goes
    // red: a job that has failed three times is claimed a fourth.
    $run = claimFixtureRun();
    $job = claimFixtureJob($run, 'JOB0000000000000000000001', overrides: ['attempts' => 3]);

    expect(claimAs('worker-one'))->toBeNull()
        ->and((int) claimJobRow($job)['attempts'])->toBe(3)
        ->and((string) claimJobRow($job)['status'])->toBe('pending');

    // The control, in the same dataset: one attempt below the maximum is still
    // claimable, so the cap is a comparison and not a refusal of every retry.
    $second = claimFixtureJob($run, 'JOB0000000000000000000002', overrides: ['attempts' => 2]);

    expect(claimAs('worker-one'))->toBeInstanceOf(ClaimedJob::class)
        ->and((int) claimJobRow($second)['attempts'])->toBe(3);
});

/*
|--------------------------------------------------------------------------
| AC-50 - the per-server cap
|--------------------------------------------------------------------------
*/

it('caps claims per server and leaves the other server claimable', function () {
    config()->set('schema.per_server_limit', 1);

    $run = claimFixtureRun();
    $firstOnA = claimFixtureJob($run, 'JOB0000000000000000000001', 'SERVERA00000000000000000A');
    $secondOnA = claimFixtureJob($run, 'JOB0000000000000000000002', 'SERVERA00000000000000000A');
    $onB = claimFixtureJob($run, 'JOB0000000000000000000003', 'SERVERB00000000000000000B');

    // One claim on A, and the cap is now full for A.
    expect(claimAs('worker-one')->jobId)->toBe($firstOnA);

    // The second worker is not blocked by A being full: the other server is
    // claimable. A serial implementation that simply took the next job would
    // have taken A's second and passed a weaker assertion.
    expect(claimAs('worker-two')->jobId)->toBe($onB);

    // Nothing left: A is capped and B is empty.
    expect(claimAs('worker-three'))->toBeNull();

    // When A's claim finishes, A's second job becomes claimable.
    DB::connection('master')->table('tenant_migration_jobs')
        ->where('id', $firstOnA)
        ->update(['status' => 'done', 'lock_expires_at' => null]);

    expect(claimAs('worker-three')->jobId)->toBe($secondOnA);
});

/*
|--------------------------------------------------------------------------
| The order the planner wrote
|--------------------------------------------------------------------------
*/

it('tries jobs in the order the plan wrote them', function () {
    $run = claimFixtureRun();
    $first = claimFixtureJob($run, 'JOB0000000000000000000001');
    $second = claimFixtureJob($run, 'JOB0000000000000000000002');

    // Written smallest-tenant-first by the planner (ascending size_bytes), so
    // claiming in id order is claiming in plan order without reading the
    // mapping again.
    expect(claimAs('worker-one')->jobId)->toBe($first)
        ->and(claimAs('worker-two')->jobId)->toBe($second);
});
