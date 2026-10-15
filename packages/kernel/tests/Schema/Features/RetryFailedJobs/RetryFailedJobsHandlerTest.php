<?php

use Flexipos\Kernel\Schema\Features\RetryFailedJobs\RetryFailedJobs;
use Flexipos\Kernel\Schema\Features\RetryFailedJobs\RetryFailedJobsHandler;
use Flexipos\Kernel\Schema\Features\RetryFailedJobs\RetryFailedJobsResult;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Making a run's failed jobs claimable again
|--------------------------------------------------------------------------
|
| T-008 step 11, AC-46's retry-selection half. It moved here from step 10
| because it has no input that binds until an execution path exists: a retry
| that selects jobs nothing then executes cannot be told from one that selects
| the wrong jobs. The "nothing ran twice" half is asserted where the execution
| happens, in RunTenantMigrationsHandlerTest.
|
| Red is proven by omitting the mechanism, on the suite's SQLite master. No
| engine, and nothing destructive: the only statement is an UPDATE on master
| rows.
|
| **The statement pin** (plan section 7, fourth shape). The candidate read
| filters on `status = failed` and `attempts < max`, and the reset statement
| re-checks both. One process cannot tell the two sites apart, so the executed
| UPDATE's WHERE is asserted from the query log - and the behavioural cases
| below say which term each of them is about.
|
*/

require_once __DIR__.'/../../../Support/migration-run-fixture.php';

uses(TestCase::class);

beforeEach(function () {
    masterMappingMigrate();

    config()->set('schema.max_attempts', 3);
});

/**
 * One run row for jobs to hang off.
 */
function retryFixtureRun(string $runId): string
{
    DB::connection('master')->table('tenant_migration_runs')->insert([
        'id' => $runId,
        'target_version' => 7,
        'mode' => 'bulk',
        'concurrency' => 1,
        'status' => 'completed_with_failures',
        'total' => 0,
        'pending' => 0,
        'created_at' => '2026-10-15 09:00:00.000',
        'updated_at' => '2026-10-15 09:00:00.000',
    ]);

    return $runId;
}

/**
 * One job row in whatever state the case is about.
 *
 * @param  array<string, mixed>  $overrides
 */
function retryFixtureJob(string $runId, string $jobId, array $overrides = []): string
{
    DB::connection('master')->table('tenant_migration_jobs')->insert(array_merge([
        'id' => $jobId,
        'run_id' => $runId,
        'tenant_id' => str_pad($jobId, 26, 'T'),
        'server_id' => 'SERVERA00000000000000000A',
        'from_version' => 6,
        'to_version' => 7,
        'status' => 'failed',
        'attempts' => 1,
        'claimed_by' => 'worker-one',
        'claimed_at' => '2026-10-15 09:00:00.000',
        'lock_expires_at' => '2026-10-15 09:30:00.000',
        'finished_at' => '2026-10-15 09:05:00.000',
        'error_class' => 'RuntimeException',
        'error_message' => 'FX-2 was told to fail on this tenant',
        'error_migration' => '2026_10_15_000002_fx2_fails_on_one_tenant',
    ], $overrides));

    return $jobId;
}

/**
 * @return array<string, mixed>
 */
function retryFixtureRow(string $jobId): array
{
    return (array) DB::connection('master')->table('tenant_migration_jobs')->where('id', $jobId)->first();
}

/**
 * @param  list<string>|null  $tenantIds
 */
function retryRun(string $runId, ?array $tenantIds = null): RetryFailedJobsResult
{
    return app(RetryFailedJobsHandler::class)->handle(new RetryFailedJobs($runId, $tenantIds));
}

/*
|--------------------------------------------------------------------------
| What a retry resets, and what it keeps
|--------------------------------------------------------------------------
*/

it('makes a failed job claimable again and clears the lease and the error', function () {
    $run = retryFixtureRun('RUN0000000000000000000001');
    $job = retryFixtureJob($run, 'JOB0000000000000000000001');

    $result = retryRun($run);

    expect($result->reset)->toBe([$job])
        ->and($result->exhausted)->toBe([]);

    $row = retryFixtureRow($job);

    expect((string) $row['status'])->toBe('pending')
        ->and($row['claimed_by'])->toBeNull()
        ->and($row['lock_expires_at'])->toBeNull()
        ->and($row['finished_at'])->toBeNull()
        ->and($row['error_class'])->toBeNull()
        ->and($row['error_message'])->toBeNull()
        ->and($row['error_migration'])->toBeNull()
        // Kept, deliberately: the count is what stops a job that fails for a
        // structural reason being retried for ever (D-20). A retry that reset
        // it would turn the cap into a suggestion.
        ->and((int) $row['attempts'])->toBe(1);
});

it('leaves a job that has reached the attempt maximum failed, and says so', function () {
    // This case is about the **attempts < max** term of the WHERE.
    $run = retryFixtureRun('RUN0000000000000000000001');
    $exhausted = retryFixtureJob($run, 'JOB0000000000000000000001', ['attempts' => 3]);
    $retryable = retryFixtureJob($run, 'JOB0000000000000000000002', ['attempts' => 2]);

    $result = retryRun($run);

    expect($result->reset)->toBe([$retryable])
        ->and($result->exhausted)->toBe([$exhausted])
        ->and((string) retryFixtureRow($exhausted)['status'])->toBe('failed')
        ->and((string) retryFixtureRow($retryable)['status'])->toBe('pending');
});

it('never touches a job that is done', function () {
    // This case is about the **status = failed** term, and it is AC-46's
    // "the tenants already done have unchanged migrations tables" at its
    // earliest possible point: a job dragged back to pending would be
    // re-applied by the next claim.
    $run = retryFixtureRun('RUN0000000000000000000001');
    $done = retryFixtureJob($run, 'JOB0000000000000000000001', [
        'status' => 'done',
        'error_class' => null,
        'error_message' => null,
        'error_migration' => null,
    ]);

    $result = retryRun($run);

    expect($result->reset)->toBe([])
        ->and((string) retryFixtureRow($done)['status'])->toBe('done');
});

it('never touches another run', function () {
    $mine = retryFixtureRun('RUN0000000000000000000001');
    $theirs = retryFixtureRun('RUN0000000000000000000002');

    $job = retryFixtureJob($mine, 'JOB0000000000000000000001');
    $other = retryFixtureJob($theirs, 'JOB0000000000000000000002');

    retryRun($mine);

    expect((string) retryFixtureRow($job)['status'])->toBe('pending')
        ->and((string) retryFixtureRow($other)['status'])->toBe('failed');
});

it('narrows to the tenants the operator named', function () {
    $run = retryFixtureRun('RUN0000000000000000000001');
    $named = retryFixtureJob($run, 'JOB0000000000000000000001');
    $unnamed = retryFixtureJob($run, 'JOB0000000000000000000002');

    retryRun($run, [(string) retryFixtureRow($named)['tenant_id']]);

    expect((string) retryFixtureRow($named)['status'])->toBe('pending')
        ->and((string) retryFixtureRow($unnamed)['status'])->toBe('failed');
});

/*
|--------------------------------------------------------------------------
| The statement, not the pair
|--------------------------------------------------------------------------
*/

it('resets with one statement whose where re-checks the status and the attempt count', function () {
    $run = retryFixtureRun('RUN0000000000000000000001');
    retryFixtureJob($run, 'JOB0000000000000000000001');

    $touched = runFixtureConnectionsUsedBy(static fn () => retryRun($run));

    expect($touched['thrown'])->toBeNull()
        ->and($touched['queried'])->toBe(['master']);

    $updates = [];

    foreach ($touched['sql'] as $sql) {
        if (str_starts_with(strtolower(ltrim($sql)), 'update')) {
            $updates[] = $sql;
        }
    }

    expect($updates)->toHaveCount(1);

    // Deleting either term from the statement while leaving it in the read
    // keeps every behavioural case above green, because in one process the
    // read never offers a row the statement would wrongly reset. This is the
    // case that goes red on that mutation, and it must not be merged into the
    // behavioural ones as duplication.
    $where = substr($updates[0], (int) strpos($updates[0], ' where '));

    expect($where)->toContain('"status" = ?')
        ->and($where)->toContain('"attempts" < ?');
});
