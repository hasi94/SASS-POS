<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Schema\Features\ReportSchemaStatus;

use Flexipos\Kernel\Schema\Features\PlanMigrationRun\PlanMigrationRunHandler;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;

/**
 * The behind report (AC-57).
 *
 * **Who is counted is the criterion, not a filter.** Only `active` and
 * `read_only` tenants appear - the same two statuses a bulk run touches - and
 * the list is read from `PlanMigrationRunHandler::BULK_STATUSES` rather than
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
 */
final class ReportSchemaStatusHandler
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly ConfigRepository $config,
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
        );
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
            ->whereIn('status', PlanMigrationRunHandler::BULK_STATUSES)
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
        return $this->database
            ->connection((string) $this->config->get('tenancy.master_connection', 'master'))
            ->table('tenant_databases');
    }
}
