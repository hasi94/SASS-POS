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
use Flexipos\Kernel\Schema\Features\MigrateTenant\MigrateTenantResult;
use Flexipos\Kernel\Schema\Features\PlanMigrationRun\PlanMigrationRun;
use Flexipos\Kernel\Schema\Features\PlanMigrationRun\PlanMigrationRunHandler;
use Flexipos\Kernel\Schema\Features\RetryFailedJobs\RetryFailedJobs;
use Flexipos\Kernel\Schema\Features\RetryFailedJobs\RetryFailedJobsHandler;
use Flexipos\Kernel\Schema\Support\RunnerClock;
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
    ) {}

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

    private function markRunning(string $runId): void
    {
        $now = $this->clock->timestamp();

        $this->table('tenant_migration_runs')
            ->where('id', $runId)
            ->update([
                'status' => self::RUN_RUNNING,
                'started_at' => $now,
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

        $status = ($counts['failed'] ?? 0) > 0
            ? self::RUN_COMPLETED_WITH_FAILURES
            : self::RUN_COMPLETED;

        $now = $this->clock->timestamp();

        $this->table('tenant_migration_runs')
            ->where('id', $runId)
            ->update(array_merge($counts, [
                'status' => $status,
                'total' => $total,
                'finished_at' => $now,
                'updated_at' => $now,
            ]));

        return new RunTenantMigrationsResult(
            runId: $runId,
            targetVersion: $target,
            status: $status,
            jobs: $jobs,
            counts: $counts,
            exitCode: $status === self::RUN_COMPLETED ? 0 : 1,
        );
    }

    private function table(string $table): Builder
    {
        return $this->database
            ->connection((string) $this->config->get('tenancy.master_connection', 'master'))
            ->table($table);
    }
}
