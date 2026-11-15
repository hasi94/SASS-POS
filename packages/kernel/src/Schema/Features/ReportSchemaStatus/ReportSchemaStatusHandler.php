<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Schema\Features\ReportSchemaStatus;

use Flexipos\Kernel\Schema\Features\MigrateTenant\MigrateTenantHandler;
use Flexipos\Kernel\Schema\Features\RetryFailedJobs\RetryFailedJobsHandler;
use Flexipos\Kernel\Schema\Features\RunTenantMigrations\RunTenantMigrationsHandler;
use Flexipos\Kernel\Tenancy\TenantStatusPolicy;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;

/**
 * The behind report (AC-57).
 *
 * **Who is counted is the criterion, not a filter.** Only `active` and
 * `read_only` tenants appear - the same two statuses a bulk run touches - and
 * the list is read from the injected `TenantStatusPolicy` rather than
 * repeated here, so a report that says "three tenants are behind" is counting
 * the tenants a run would actually reach. An `archived` tenant at an ancient
 * version is not behind; it is gone, and a report that listed it would send
 * somebody to migrate a database nobody serves.
 *
 * **Strictly below.** A tenant *at* the required version is not behind. That
 * boundary is the one a `<=` would get wrong and the report would then ask an
 * operator to migrate the whole platform every time the floor moved, so the
 * control sits in the test at exactly that input.
 *
 * Master-only: no tenant connection is opened, which is why this half of step
 * 12 is provable while the engine is on hold. The number it reports is the
 * **mirror**, `tenant_databases.schema_version`; whether the mirror still
 * matches the database is `DetectSchemaDrift`'s question and the two are
 * deliberately not merged - a report that opened every tenant database to
 * check would be unrunnable on a platform of any size.
 *
 * **The runs section** (plan section 19.2, step 13f) is the second subject
 * and the second query. `running` is the one run status that does not say
 * what is happening: since step 13f a run stays `running` while any job is
 * `pending` or `running`, and it also stays `running` after a dispatched run
 * has drained and nobody has invoked the finishing pass yet. Those need
 * opposite responses - wait, or run `tenant:migrate --run=<id>` - so the
 * report counts each running run's jobs and says which of the two it is.
 * Still master-only: both subjects live in the master schema.
 */
final class ReportSchemaStatusHandler
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly ConfigRepository $config,
        private readonly TenantStatusPolicy $statusPolicy,
    ) {}

    public function handle(ReportSchemaStatus $query): SchemaStatusReport
    {
        $byVersion = [];
        $byServer = [];
        $behind = [];

        foreach ($this->counted() as $row) {
            $version = $row['schema_version'];
            $server = $row['server_id'];

            $byVersion[$version] = ($byVersion[$version] ?? 0) + 1;
            $byServer[$server][$version] = ($byServer[$server][$version] ?? 0) + 1;

            if ($version < $query->requiredVersion) {
                $behind[] = $row;
            }
        }

        ksort($byVersion);
        ksort($byServer);

        foreach ($byServer as $server => $versions) {
            ksort($versions);
            $byServer[$server] = $versions;
        }

        return new SchemaStatusReport(
            requiredVersion: $query->requiredVersion,
            byVersion: $byVersion,
            byServer: $byServer,
            behind: $behind,
            oldestLaggard: $this->oldestOf($behind),
            runs: $this->runs(),
        );
    }

    /**
     * Every `running` run with its jobs counted by status, lowest id first.
     *
     * One statement, a left join from the runs: a run that is `running` with
     * no job rows at all is still a run somebody has to deal with, and an
     * inner join would hide it.
     *
     * `outstanding` is the pair of non-terminal statuses, counted here rather
     * than by the caller so the report and `RunTenantMigrationsHandler` agree
     * on what "not finished" means - that agreement is the whole point of the
     * section.
     *
     * @return list<array{run_id: string, counts: array<string, int>, outstanding: int}>
     */
    private function runs(): array
    {
        $byRun = [];

        $rows = $this->master()
            ->table('tenant_migration_runs')
            ->leftJoin(
                'tenant_migration_jobs',
                'tenant_migration_jobs.run_id',
                '=',
                'tenant_migration_runs.id'
            )
            ->where('tenant_migration_runs.status', RunTenantMigrationsHandler::RUN_RUNNING)
            ->groupBy('tenant_migration_runs.id', 'tenant_migration_jobs.status')
            ->orderBy('tenant_migration_runs.id')
            ->get([
                'tenant_migration_runs.id as run_id',
                'tenant_migration_jobs.status as job_status',
                new Expression('count(tenant_migration_jobs.id) as job_count'),
            ]);

        foreach ($rows as $row) {
            $row = (array) $row;
            $runId = (string) $row['run_id'];
            $status = $row['job_status'];

            $byRun[$runId] ??= [];

            if (is_string($status) && $status !== '') {
                $byRun[$runId][$status] = ((int) $row['job_count']) + ($byRun[$runId][$status] ?? 0);
            }
        }

        $runs = [];

        foreach ($byRun as $runId => $counts) {
            ksort($counts);

            $runs[] = [
                'run_id' => $runId,
                'counts' => $counts,
                'outstanding' => ($counts[RetryFailedJobsHandler::JOB_PENDING] ?? 0)
                    + ($counts[MigrateTenantHandler::JOB_RUNNING] ?? 0),
            ];
        }

        return $runs;
    }

    /**
     * The tenants this report is about, lowest version first.
     *
     * @return list<array{tenant_id: string, server_id: string, schema_version: int}>
     */
    private function counted(): array
    {
        $rows = [];

        $query = $this->table()
            ->select(['tenant_id', 'server_id', 'schema_version'])
            ->whereIn('status', $this->statusPolicy->bulk())
            ->orderBy('schema_version')
            ->orderBy('tenant_id');

        foreach ($query->get() as $row) {
            $row = (array) $row;

            $rows[] = [
                'tenant_id' => (string) $row['tenant_id'],
                'server_id' => (string) $row['server_id'],
                'schema_version' => (int) $row['schema_version'],
            ];
        }

        return $rows;
    }

    /**
     * The single tenant furthest behind, or null when nobody is.
     *
     * The rows arrive lowest-version-first and then by tenant id, so the
     * first one is the answer and ties break the same way twice running
     * rather than however the engine felt.
     *
     * @param  list<array{tenant_id: string, server_id: string, schema_version: int}>  $behind
     */
    private function oldestOf(array $behind): ?string
    {
        return $behind === [] ? null : $behind[0]['tenant_id'];
    }

    private function table(): Builder
    {
        return $this->master()->table('tenant_databases');
    }

    private function master(): Connection
    {
        /** @var Connection $connection */
        $connection = $this->database
            ->connection((string) $this->config->get('tenancy.master_connection', 'master'));

        return $connection;
    }
}
