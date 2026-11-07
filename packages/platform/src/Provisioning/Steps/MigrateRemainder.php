<?php

declare(strict_types=1);

namespace Flexipos\Platform\Provisioning\Steps;

use Flexipos\Kernel\Schema\Exceptions\MigrationRunRefused;
use Flexipos\Kernel\Schema\Exceptions\RunnerLoopRefused;
use Flexipos\Kernel\Schema\Features\MigrateTenant\MigrateTenantHandler;
use Flexipos\Kernel\Schema\Features\PlanMigrationRun\PlanMigrationRun;
use Flexipos\Kernel\Schema\Features\PlanMigrationRun\PlanMigrationRunHandler;
use Flexipos\Kernel\Schema\Features\RetryFailedJobs\RetryFailedJobs;
use Flexipos\Kernel\Schema\Features\RunTenantMigrations\RunTenantMigrations;
use Flexipos\Kernel\Schema\Features\RunTenantMigrations\RunTenantMigrationsHandler;
use Flexipos\Kernel\Schema\Support\MigrationSet;
use Flexipos\Kernel\Tenancy\TenantStatusPolicy;
use Flexipos\Platform\Exceptions\PlatformRefused;
use Flexipos\Platform\Provisioning\ProvisioningStep;
use Flexipos\Platform\Provisioning\RunContext;
use Flexipos\Platform\Provisioning\StepJournal;
use Flexipos\Platform\Provisioning\StepName;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;

/**
 * `migrate_remainder`, driving T-008's own runner rather than rebuilding it
 * (T-026 Ruling 8, AC-20 to AC-23). This step writes no migration-execution
 * code at all; it is a thin driver over `PlanMigrationRunHandler` and
 * `RunTenantMigrationsHandler`, exactly as every other T-008 caller uses
 * them.
 *
 * **A named selector, never bulk** (AC-20, D-4). `tenantIds: [$tenantId]` is
 * what makes a `provisioning`-status tenant enterable at all
 * ({@see TenantStatusPolicy::NAMED_ONLY_STATUSES}) -
 * a bulk plan would silently skip this tenant rather than migrate it.
 *
 * **The run id is journalled before anything executes (Ruling 2, D-13,
 * AC-23).** On the first attempt, `PlanMigrationRunHandler` is called
 * directly (not through the runner's own planning path) so the id it returns
 * can be committed to this step's journal *before* `RunTenantMigrationsHandler`
 * ever claims or runs a job. A resumed attempt reads that id back and hands
 * it to the runner as a `--run=` resumption, which plans nothing - so a
 * tenant that already has a migration run in flight or completed never gets
 * a second one.
 *
 * **Blocks until this tenant's job is terminal (AC-21).** The runner is
 * called in-process (`retryFailed: true`, a dedicated `workerId`), which
 * claims and executes the run's one job synchronously before this method
 * returns - there is nothing queued left for a later process to finish.
 * `retryFailed` is what lets a resumed attempt reclaim a job this run's own
 * earlier attempt left `failed`, scoped to this tenant only
 * ({@see RetryFailedJobs}).
 *
 * **The job's own terminal status decides this step's outcome (AC-22).** A
 * `done` job journals `{migration_run_id, schema_version}`, read back from
 * the mapping T-008's own handler already mirrored - never recomputed here.
 * A `failed` job fails this step with the job's own already-sanitised
 * `error_message`: `seed_core` never runs against a half-migrated database.
 * A `skipped` job (the per-tenant lock was held by somebody else) fails this
 * step asking for a later resume, rather than being treated as either a
 * success or a permanent failure. `tenant_databases.status` is never written
 * here - that is exactly what `MigrateTenantHandler`'s own success/failure
 * paths already do (AC-22's "left exactly as it leaves it").
 *
 * **`MigrationRunRefused`/`RunnerLoopRefused` are step failures, not crashes**
 * (Ruling 8.4): both are caught and rethrown as a {@see PlatformRefused},
 * carrying the runner's own message, so the provisioning run's own
 * sanitiser/journal machinery handles them exactly like any other step
 * failure.
 */
final class MigrateRemainder implements ProvisioningStep
{
    public function __construct(
        private readonly PlanMigrationRunHandler $planner,
        private readonly RunTenantMigrationsHandler $runner,
        private readonly ConfigRepository $config,
        private readonly Application $app,
        private readonly DatabaseManager $database,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function run(RunContext $context, StepJournal $journal): array
    {
        $reserved = $journal->outputOf(StepName::ReserveIdentity);
        $tenantId = (string) $reserved['tenant_id'];
        $set = $this->set();

        $saved = $journal->read();
        $runId = $saved['migration_run_id'] ?? null;

        if ($runId === null) {
            $runId = $this->plan($tenantId, $set);

            // Intent first: the run id is committed before this step ever
            // calls the runner, so a resumed attempt finds and reuses it
            // rather than planning a second run for this tenant (AC-23).
            $journal->write(['migration_run_id' => $runId]);
        }

        $runId = (string) $runId;

        try {
            $this->runner->handle(new RunTenantMigrations(
                set: $set,
                tenantIds: [$tenantId],
                runId: $runId,
                retryFailed: true,
                workerId: 'provision-'.$context->runId,
            ));
        } catch (MigrationRunRefused|RunnerLoopRefused $refused) {
            throw PlatformRefused::validation($refused->getMessage());
        }

        $job = $this->jobFor($runId, $tenantId);

        if ($job === null) {
            throw PlatformRefused::validation(
                "migrate_remainder's run [{$runId}] has no job for tenant [{$tenantId}]."
            );
        }

        return match ((string) $job['status']) {
            MigrateTenantHandler::JOB_DONE => [
                'migration_run_id' => $runId,
                'schema_version' => $this->schemaVersionOf($tenantId, (int) $job['to_version']),
            ],
            MigrateTenantHandler::JOB_FAILED => throw PlatformRefused::validation(
                (string) ($job['error_message'] ?? "migrate_remainder's job for tenant [{$tenantId}] failed with no recorded message.")
            ),
            MigrateTenantHandler::JOB_SKIPPED => throw PlatformRefused::validation(
                "migrate_remainder's job for tenant [{$tenantId}] was skipped: another session holds this tenant's migration lock. Resume later."
            ),
            default => throw PlatformRefused::validation(
                "migrate_remainder's job for tenant [{$tenantId}] is still [{$job['status']}]; the runner did not finish it in this process."
            ),
        };
    }

    public function compensate(RunContext $context): ?string
    {
        // There is nothing for this step to compensate: a migration run this
        // run started is T-008's own row, not this step's effect to undo,
        // and the tenant database itself is never dropped (D-21).
        return null;
    }

    /**
     * @throws PlatformRefused when the planner itself refuses (Ruling 8.4)
     */
    private function plan(string $tenantId, MigrationSet $set): string
    {
        try {
            $plan = $this->planner->handle(new PlanMigrationRun(
                set: $set,
                tenantIds: [$tenantId],
            ));
        } catch (MigrationRunRefused $refused) {
            throw PlatformRefused::validation($refused->getMessage());
        }

        return $plan->runId;
    }

    /**
     * The shipped tenant tree, exactly as `TenantMigrateCommand` builds it
     * (Ruling 8.1) - the one place this step reads
     * `config('schema.tenant_migration_path')`.
     */
    private function set(): MigrationSet
    {
        return MigrationSet::baseline(
            $this->app->basePath((string) $this->config->get('schema.tenant_migration_path'))
        );
    }

    /**
     * The tenant's own mirrored schema version, read from the mapping
     * `MigrateTenantHandler` already wrote - never recomputed here (Ruling
     * 8.3). Falls back to the job's own `to_version` only if the mapping row
     * is somehow gone, which cannot happen on the success path this case is
     * reached from.
     */
    private function schemaVersionOf(string $tenantId, int $fallback): int
    {
        $row = $this->table('tenant_databases')->where('tenant_id', $tenantId)->first();

        return $row === null ? $fallback : (int) ((array) $row)['schema_version'];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function jobFor(string $runId, string $tenantId): ?array
    {
        $row = $this->table('tenant_migration_jobs')
            ->where('run_id', $runId)
            ->where('tenant_id', $tenantId)
            ->first();

        return $row === null ? null : (array) $row;
    }

    private function table(string $table): Builder
    {
        return $this->database
            ->connection((string) $this->config->get('tenancy.master_connection', 'master'))
            ->table($table);
    }
}
