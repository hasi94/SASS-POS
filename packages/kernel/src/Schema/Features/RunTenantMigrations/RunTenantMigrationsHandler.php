<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Schema\Features\RunTenantMigrations;

use Flexipos\Kernel\Schema\Exceptions\MigrationRunRefused;
use Flexipos\Kernel\Schema\Exceptions\RunnerLoopRefused;
use Flexipos\Kernel\Schema\Exceptions\TenantMigrationFailed;
use Flexipos\Kernel\Schema\Features\ClaimMigrationJob\ClaimedJob;
use Flexipos\Kernel\Schema\Features\ClaimMigrationJob\ClaimMigrationJob;
use Flexipos\Kernel\Schema\Features\ClaimMigrationJob\ClaimMigrationJobHandler;
use Flexipos\Kernel\Schema\Features\MigrateTenant\MigrateTenant;
use Flexipos\Kernel\Schema\Features\MigrateTenant\MigrateTenantHandler;
use Flexipos\Kernel\Schema\Features\MigrateTenant\MigrateTenantQueueJob;
use Flexipos\Kernel\Schema\Features\MigrateTenant\MigrateTenantResult;
use Flexipos\Kernel\Schema\Features\PlanMigrationRun\PlanMigrationRun;
use Flexipos\Kernel\Schema\Features\PlanMigrationRun\PlanMigrationRunHandler;
use Flexipos\Kernel\Schema\Features\RetryFailedJobs\RetryFailedJobs;
use Flexipos\Kernel\Schema\Features\RetryFailedJobs\RetryFailedJobsHandler;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;

/**
 * Plan (or resume) a run and execute its jobs, in one worker process.
 *
 * It composes the four slices that already exist and adds the one thing none
 * of them can know: the run's outcome. AC-44 is a statement about a *run* -
 * "A and B end done, C ends failed, the run ends `completed_with_failures` and
 * the command exits non-zero" - and a handler that executes one job has no
 * vantage point from which to say it.
 *
 * **Why the loop terminates, and why there is no iteration bound.** A claim
 * either returns a job or returns null, and every job this handler executes
 * ends `done` or `failed` - neither of which is claimable - so the claimable
 * set strictly shrinks. A bound on the number of iterations would be a guess
 * about how many tenants a run may hold, and the condition it would be hiding
 * is a job this process claimed twice; that is asserted directly instead, and
 * refuses the run rather than spinning.
 *
 * **One process, several tenants, and the isolation that has to survive it**
 * (AC-42). Each job is executed inside `Tenancy::run()`, whose `finally`
 * leaves the scope, so the second tenant's job enters a process with no tenant
 * resolved exactly as the first one did. Nothing here holds a connection
 * between jobs and nothing caches a tenant id.
 *
 * A job that fails is recorded by `MigrateTenantHandler` and the run
 * continues (D-13). A job this handler cannot even start - the tenant lost its
 * mapping between the plan and the claim - throws out of the migrate handler;
 * it is caught here, the job is marked failed so the run can finish, and the
 * reason is stored.
 */
final class RunTenantMigrationsHandler
{
    /**
     * The run statuses this handler writes.
     */
    public const RUN_RUNNING = 'running';

    public const RUN_COMPLETED = 'completed';

    public const RUN_COMPLETED_WITH_FAILURES = 'completed_with_failures';

    public function __construct(
        private readonly DatabaseManager $database,
        private readonly ConfigRepository $config,
        private readonly RunnerClock $clock,
        private readonly PlanMigrationRunHandler $planner,
        private readonly ClaimMigrationJobHandler $claimer,
        private readonly MigrateTenantHandler $migrator,
        private readonly RetryFailedJobsHandler $retrier,
        private readonly Dispatcher $bus,
    ) {}

    /**
     * Plan (or resume) a run and hand each of its jobs to the queue, instead
     * of executing them in this process (AC-41).
     *
     * One job row, one queue job, on the queue the caller names - the kernel
     * is told the queue rather than reading `config('schema.queue')`, which is
     * the host's key, for the same reason it is told the tree directory. What
     * the payload may carry is `MigrateTenantQueueJob`'s business and is
     * asserted there: identifiers and a set, never a credential.
     *
     * **Only `pending` jobs are dispatched.** A `running` job is held by a
     * worker whose lease has not expired, and a second queue job for it would
     * be a second migration against the same tenant; expired leases are the
     * claim's business, and the worker that picks this job up claims it the
     * same way an in-process run does.
     *
     * **What this does not do, said plainly rather than left to be found.**
     * The run's final counters and status are written by whatever process
     * drains the last job, and a queue worker executing one job has no
     * vantage point from which to know it was the last - the same argument
     * that put `finish()` in the loop below. So a dispatched run stays
     * `running` until `tenant:migrate --run=<id>` is invoked once the queue
     * has drained: that resumes, claims nothing, and writes the counters. A
     * run watcher that does it without being asked is FP-203's.
     *
     * @return DispatchedRun the run and the job ids handed to the queue
     */
    public function dispatch(RunTenantMigrations $command, string $queue): DispatchedRun
    {
        [$runId, $target] = $this->runFor($command);

        $this->markRunning($runId);

        $dispatched = [];

        $rows = $this->table('tenant_migration_jobs')
            ->where('run_id', $runId)
            ->where('status', RetryFailedJobsHandler::JOB_PENDING)
            ->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            $job = (array) $row;
            $jobId = (string) $job['id'];

            $this->bus->dispatch(MigrateTenantQueueJob::forJob(
                runId: $runId,
                jobId: $jobId,
                tenantId: (string) $job['tenant_id'],
                workerId: $command->workerId,
                set: $command->set,
                queue: $queue,
            ));

            $dispatched[] = $jobId;
        }

        return new DispatchedRun($runId, $target, $queue, $dispatched);
    }

    /**
     * @throws MigrationRunRefused from planning, before anything is written
     * @throws RunnerLoopRefused when the invocation names a run that does not
     *                           exist, or a job comes back claimable
     */
    public function handle(RunTenantMigrations $command): RunTenantMigrationsResult
    {
        [$runId, $target] = $this->runFor($command);

        $this->markRunning($runId);

        $jobs = [];
        $seen = [];

        while (($claim = $this->claimer->handle(new ClaimMigrationJob($runId, $command->workerId))) instanceof ClaimedJob) {
            if (in_array($claim->jobId, $seen, true)) {
                throw RunnerLoopRefused::jobClaimedTwice($runId, $claim->jobId);
            }

            $seen[] = $claim->jobId;
            $jobs[] = $this->execute($command, $claim);
        }

        return $this->finish($runId, $target, $jobs);
    }

    /**
     * The run this invocation works on: an existing one, or a freshly planned
     * one.
     *
     * A resumption plans nothing. That is what makes `--retry-failed` safe to
     * run twice: re-planning would re-select against today's mapping and could
     * write a second job row for a tenant whose first one is still running.
     *
     * @return array{0: string, 1: int}
     */
    private function runFor(RunTenantMigrations $command): array
    {
        if (! $command->resumes()) {
            $plan = $this->planner->handle(new PlanMigrationRun(
                set: $command->set,
                tenantIds: $command->tenantIds,
                targetVersion: $command->targetVersion,
                initiatedBy: $command->initiatedBy,
                concurrency: $command->concurrency,
            ));

            return [$plan->runId, $plan->targetVersion];
        }

        $runId = (string) $command->runId;
        $row = $this->table('tenant_migration_runs')->where('id', $runId)->first();

        if ($row === null) {
            throw RunnerLoopRefused::runNotFound($runId);
        }

        if ($command->retryFailed) {
            $this->retrier->handle(new RetryFailedJobs($runId, $command->tenantIds));
        }

        return [$runId, (int) ((array) $row)['target_version']];
    }

    /**
     * One job, with the one failure the migrate handler raises rather than
     * records.
     */
    private function execute(RunTenantMigrations $command, ClaimedJob $claim): MigrateTenantResult
    {
        try {
            return $this->migrator->handle(new MigrateTenant($claim->jobId, $command->workerId, $command->set));
        } catch (TenantMigrationFailed $refusal) {
            // The job could not be started at all. It is marked failed here so
            // the run can reach a final status; leaving it `running` would make
            // the next claim wait for a lease nobody holds.
            $this->table('tenant_migration_jobs')
                ->where('id', $claim->jobId)
                ->where('status', MigrateTenantHandler::JOB_RUNNING)
                ->where('claimed_by', $command->workerId)
                ->update([
                    'status' => MigrateTenantHandler::JOB_FAILED,
                    'finished_at' => $this->clock->timestamp(),
                    'error_class' => $refusal::class,
                    'error_message' => $refusal->getMessage(),
                ]);

            return new MigrateTenantResult(
                jobId: $claim->jobId,
                tenantId: $claim->tenantId,
                databaseName: '',
                succeeded: false,
                schemaVersion: $claim->fromVersion ?? 0,
                applied: [],
                durationMs: 0,
                claimRetained: true,
                errorClass: $refusal::class,
                errorMessage: $refusal->getMessage(),
            );
        }
    }

    /**
     * Put the run into `running`, keeping the moment it actually started.
     *
     * **`started_at` is written once** (R-W1). Every invocation passes
     * through here, and the queued path *requires* a second one - the
     * resumption that writes the counters after the queue drains runs
     * `markRunning()` before `finish()`. Restamping would move the start
     * past the `finished_at` that `finish()` deliberately preserves, and the
     * row would report a run that ended before it began. Same rule, and the
     * same reason, as `coalesce(started_at, ...)` in the job claim: the first
     * writer of a start is the one that means it.
     */
    private function markRunning(string $runId): void
    {
        $now = $this->clock->timestamp();

        $this->table('tenant_migration_runs')
            ->where('id', $runId)
            ->update([
                'status' => self::RUN_RUNNING,
                'started_at' => $this->runTimestamp($runId, 'started_at') ?? $now,
                'updated_at' => $now,
            ]);
    }

    /**
     * The run's counters and its final status, read back from the job rows.
     *
     * Counted from the table rather than from this process's own results:
     * several workers may have executed the same run, and a tally kept in one
     * of them would describe its own share.
     *
     * **A run with a job that has not finished is not finished** (plan
     * section 19.2, step 13f). Until this step the status depended only on
     * whether anything had failed, so a run could be written `completed` with
     * `pending` and `running` jobs counted into its own row - a run whose
     * state lies, reachable the moment a job is held by another worker under
     * an unexpired lease, which is exactly the queued path. So the terminal
     * status is withheld while `pending + running > 0`: the run keeps
     * `running`, `finished_at` is not written, and the result carries the
     * outstanding count with a non-zero exit code so a cron notices.
     *
     * The counters are written either way. They are a true count of the job
     * rows whatever the run's status is, and the status report needs them to
     * tell "running, N outstanding" from "running, awaiting its final
     * counters".
     *
     * **Idempotent on a run that is already terminal.** `finished_at` is kept
     * rather than restamped, so a second invocation - the one that writes the
     * counters after a dispatched run drains - leaves the moment the work
     * actually ended in place and exits 0.
     *
     * @param  list<MigrateTenantResult>  $jobs
     */
    private function finish(string $runId, int $target, array $jobs): RunTenantMigrationsResult
    {
        $counts = ['pending' => 0, 'running' => 0, 'done' => 0, 'failed' => 0, 'skipped' => 0];
        $total = 0;

        foreach ($this->table('tenant_migration_jobs')->where('run_id', $runId)->get() as $row) {
            $status = (string) ((array) $row)['status'];
            $counts[$status] = ($counts[$status] ?? 0) + 1;
            $total++;
        }

        $outstanding = ($counts['pending'] ?? 0) + ($counts['running'] ?? 0);

        $status = match (true) {
            $outstanding > 0 => self::RUN_RUNNING,
            ($counts['failed'] ?? 0) > 0 => self::RUN_COMPLETED_WITH_FAILURES,
            default => self::RUN_COMPLETED,
        };

        $now = $this->clock->timestamp();

        $update = array_merge($counts, [
            'status' => $status,
            'total' => $total,
            'updated_at' => $now,
        ]);

        if ($outstanding === 0) {
            $update['finished_at'] = $this->runTimestamp($runId, 'finished_at') ?? $now;
        }

        $this->table('tenant_migration_runs')->where('id', $runId)->update($update);

        return new RunTenantMigrationsResult(
            runId: $runId,
            targetVersion: $target,
            status: $status,
            jobs: $jobs,
            counts: $counts,
            outstanding: $outstanding,
            exitCode: $status === self::RUN_COMPLETED ? 0 : 1,
        );
    }

    /**
     * One of the run's own moments, if it has been written already.
     *
     * One reader for both `started_at` and `finished_at`, so "a moment is
     * written once" cannot hold for one column and lapse for the other -
     * which is exactly how R-W1 arrived: the rule was built for
     * `finished_at` and not for its twin.
     */
    private function runTimestamp(string $runId, string $column): ?string
    {
        $row = $this->table('tenant_migration_runs')->where('id', $runId)->first();

        if ($row === null) {
            return null;
        }

        $value = ((array) $row)[$column] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function table(string $table): Builder
    {
        return $this->database
            ->connection((string) $this->config->get('tenancy.master_connection', 'master'))
            ->table($table);
    }
}
