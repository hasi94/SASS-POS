<?php

use Flexipos\Kernel\Schema\Features\ReportSchemaStatus\ReportSchemaStatus;
use Flexipos\Kernel\Schema\Features\ReportSchemaStatus\ReportSchemaStatusHandler;
use Flexipos\Kernel\Schema\Features\ReportSchemaStatus\SchemaStatusReport;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-008 step 13f: the status report learns the difference between two
| `running` runs
|--------------------------------------------------------------------------
|
| Plan section 19.2's second condition. The report named neither runs nor
| jobs - it reported tenant schema versions - so a run left `running` was
| invisible to the one command an operator runs to ask where the platform is.
|
| `running` has two meanings and they call for opposite responses:
|
|  - **work is in flight**: a job is `pending` or held by a worker. Wait.
|  - **every job is terminal**: the jobs are done and nobody has written the
|    run's final counters. That is the normal end state of a dispatched run
|    (the handler's dispatch() says so), and the remedy is one invocation of
|    `tenant:migrate --run=<id>`.
|
| The two are indistinguishable in the run row alone, which is why this is a
| count of the jobs and not a read of the run's own counters: those counters
| are written by the finishing pass, so for a run that has never had one they
| are the planner's figures and describe the moment the run was planned.
|
| Master-only, no engine: both subjects are in the master schema.
*/

require_once __DIR__.'/../../../Support/migration-run-fixture.php';

uses(TestCase::class);

beforeEach(function () {
    masterMappingMigrate();
});

function runSectionReport(): SchemaStatusReport
{
    return app(ReportSchemaStatusHandler::class)->handle(new ReportSchemaStatus(6));
}

/**
 * One run row in the given status, with the planner's own stale counters.
 */
function runSectionRun(string $runId, string $status, int $plannedTotal = 2): string
{
    DB::connection('master')->table('tenant_migration_runs')->insert([
        'id' => $runId,
        'target_version' => 6,
        'mode' => 'bulk',
        'concurrency' => 1,
        'status' => $status,
        'total' => $plannedTotal,
        // Deliberately wrong for a run that has executed anything: this is
        // what the planner wrote, and a report that read the run row instead
        // of counting the jobs would print it.
        'pending' => $plannedTotal,
        'created_at' => '2026-10-15 09:00:00.000',
        'updated_at' => '2026-10-15 09:00:00.000',
    ]);

    return $runId;
}

/**
 * One job row of a run, in the given status.
 */
function runSectionJob(string $runId, string $jobId, string $status): void
{
    DB::connection('master')->table('tenant_migration_jobs')->insert([
        'id' => $jobId,
        'run_id' => $runId,
        // Short and distinct: masterMappingId() truncates at 26 characters,
        // so two tenant ids built from whole job ids would collide on
        // (run_id, tenant_id) rather than test anything.
        'tenant_id' => masterMappingId('t-'.substr($jobId, 0, 4).substr($jobId, -3)),
        'server_id' => masterMappingId('server-one'),
        'from_version' => 0,
        'to_version' => 6,
        'status' => $status,
        'attempts' => 1,
    ]);
}

it('tells a run with work in flight from one that is only waiting to be finished', function () {
    $inFlight = runSectionRun('RUN0000000000000000000001', 'running');
    runSectionJob($inFlight, 'JOBA000000000000000000001', 'done');
    runSectionJob($inFlight, 'JOBA000000000000000000002', 'pending');
    runSectionJob($inFlight, 'JOBA000000000000000000003', 'running');

    $drained = runSectionRun('RUN0000000000000000000002', 'running');
    runSectionJob($drained, 'JOBB000000000000000000001', 'done');
    runSectionJob($drained, 'JOBB000000000000000000002', 'failed');

    expect(runSectionReport()->runs)->toBe([
        [
            'run_id' => $inFlight,
            'counts' => ['done' => 1, 'pending' => 1, 'running' => 1],
            'outstanding' => 2,
        ],
        [
            'run_id' => $drained,
            'counts' => ['done' => 1, 'failed' => 1],
            'outstanding' => 0,
        ],
    ]);
});

it('leaves out the runs that are not running', function () {
    // The control. A section that listed every run would bury the one row
    // an operator has to act on under every run the platform has ever done.
    $completed = runSectionRun('RUN0000000000000000000003', 'completed');
    runSectionJob($completed, 'JOBC000000000000000000001', 'done');

    runSectionRun('RUN0000000000000000000004', 'planned');

    expect(runSectionReport()->runs)->toBe([]);
});

it('still lists a running run that has no job rows at all', function () {
    // An inner join would hide this one, and it is a run somebody has to
    // deal with: `running` with nothing to run is the state a planner that
    // selected nobody leaves behind.
    $empty = runSectionRun('RUN0000000000000000000005', 'running', plannedTotal: 0);

    expect(runSectionReport()->runs)->toBe([
        ['run_id' => $empty, 'counts' => [], 'outstanding' => 0],
    ]);
});

it('prints both readings, and names the invocation that finishes the drained one', function () {
    $inFlight = runSectionRun('RUN0000000000000000000001', 'running');
    runSectionJob($inFlight, 'JOBA000000000000000000001', 'pending');

    $drained = runSectionRun('RUN0000000000000000000002', 'running');
    runSectionJob($drained, 'JOBB000000000000000000001', 'done');

    config()->set('tenancy.required_schema_version', 6);

    expect(Artisan::call('tenant:schema-status'))->toBe(0);

    $output = preg_replace('/\s+/', ' ', (string) Artisan::output());

    expect($output)->toContain('run '.$inFlight)
        ->and($output)->toContain('1 job(s) outstanding')
        ->and($output)->toContain('every job terminal')
        ->and($output)->toContain('tenant:migrate --run='.$drained);
});

/*
|--------------------------------------------------------------------------
| The recorded mutation
|--------------------------------------------------------------------------
|
| Drop `pending` from the outstanding sum in ReportSchemaStatusHandler::runs(),
| leaving only `running`: the first and last cases above go red, because the
| in-flight run then reads as one that is merely waiting for its final
| counters - which is the exact confusion this section exists to remove.
| MEASURED on 2026-10-15, 2 failed / 2 passed.
|
| The two cases that stay green under it are the two that carry no pending
| job: "leaves out the runs that are not running" and the empty run. They are
| controls for the filter and the left join, not for the count.
*/
