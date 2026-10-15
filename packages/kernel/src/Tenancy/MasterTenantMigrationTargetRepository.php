<?php

namespace Flexipos\Kernel\Tenancy;

use Flexipos\Kernel\Tenancy\Contracts\TenantMigrationTargetRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\DatabaseManager;

/**
 * The runner's mapping, read from the master schema: the same joined read as
 * the serving reader, selecting the **migrate** pair instead of the app pair.
 *
 * Two readers over one pair of tables, deliberately, rather than one reader
 * with a flag. A flag would mean a single select list naming
 * `migrate_credential_ref`, reachable from a request by passing the wrong
 * argument; as it is, the serving read's SQL does not mention the column at
 * all.
 *
 * **Not cached.** The runner reads a target once per tenant per migration, so a
 * window would buy nothing, and a stale target is the one thing a migration
 * must never connect as after a rotation - D-19 has the runner *evicting* the
 * serving cache, not consulting one of its own.
 *
 * Not named in plan section 4, which lists the port and the record for step 7
 * and leaves the master-backed driver implicit. It is written here because the
 * step's own proof needs it: AC-19's exemption is asserted by the runner
 * entering a tenant at the required version minus one, and an exemption with no
 * mapping source behind it cannot be entered at all.
 */
final class MasterTenantMigrationTargetRepository implements TenantMigrationTargetRepository
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly ConfigRepository $config,
    ) {}

    public function find(string $tenantId): ?TenantMigrationTarget
    {
        if (trim($tenantId) === '') {
            return null;
        }

        $row = $this->database->connection((string) $this->config->get('tenancy.master_connection', 'master'))
            ->table('tenant_databases')
            ->join('database_servers', 'database_servers.id', '=', 'tenant_databases.server_id')
            ->where('tenant_databases.tenant_id', $tenantId)
            ->select([
                'tenant_databases.tenant_id',
                'tenant_databases.database_name',
                'tenant_databases.migrate_username',
                'tenant_databases.migrate_credential_ref',
                'tenant_databases.credential_version',
                'tenant_databases.status',
                'tenant_databases.schema_version',
                'database_servers.engine',
                'database_servers.host',
                'database_servers.port',
            ])
            ->first();

        if ($row === null) {
            return null;
        }

        $row = (array) $row;

        return new TenantMigrationTarget(
            tenantId: (string) $row['tenant_id'],
            driver: (string) $row['engine'],
            host: (string) $row['host'],
            port: (int) $row['port'],
            database: (string) $row['database_name'],
            migrateUsername: (string) $row['migrate_username'],
            migrateCredentialRef: (string) $row['migrate_credential_ref'],
            status: (string) $row['status'],
            schemaVersion: (int) $row['schema_version'],
            credentialVersion: (int) $row['credential_version'],
        );
    }
}
