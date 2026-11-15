<?php

namespace Flexipos\Kernel\Tenancy;

use Flexipos\Kernel\Tenancy\Contracts\TenantDatabaseRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\DatabaseManager;

/**
 * The mapping, read from the master schema: one joined read of
 * `tenant_databases` and `database_servers` (AC-12).
 *
 * This is the implementation T-003's contract was shaped for, and it is
 * deliberately **not** a vertical slice: it is a driver behind an existing port,
 * so it lives beside the port rather than in `Schema/Features/`. Slices are for
 * features; an adapter for a published contract lives with the contract.
 *
 * Three properties worth stating, because each is a refusal rather than a
 * behaviour:
 *
 * - **One statement.** The join is the point: a reader that fetched the mapping
 *   and then the server row would answer identically and cost two round trips
 *   per request, on the one query every tenant-scoped request makes.
 * - **An inner join, so a mapping whose server row is absent is a null.** Not a
 *   partially filled record with an empty host, which is the shape that turns
 *   "unreachable" into "connected to something else".
 * - **The migrate pair is not in the select list at all.** AC-18 is satisfied
 *   twice over: the record has nowhere to put a migrate username or credential
 *   reference, and this read never asks the master for them, so they are absent
 *   from the result set rather than dropped from it afterwards.
 * - **Null is never a default.** No column is defaulted, no status is corrected
 *   and no version is normalised: the status gate and the version gate decide
 *   what those values mean, and a reader that tidied them here would make both
 *   gates untestable.
 *
 * It holds no cache of its own. The 300-second window is
 * CachingTenantDatabaseRepository's, which decorates this one, so "read the
 * master" and "do not read the master twice" are separable claims about
 * separable objects.
 *
 * No Eloquent model: a model on the master connection would be a second place
 * where a tenant mapping can be written, and writing the mapping is
 * provisioning's job (FP-206).
 */
final class MasterTenantDatabaseRepository implements TenantDatabaseRepository
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly ConfigRepository $config,
    ) {}

    public function find(string $tenantId): ?TenantDatabase
    {
        if (trim($tenantId) === '') {
            return null;
        }

        $row = $this->database->connection($this->masterConnection())
            ->table('tenant_databases')
            ->join('database_servers', 'database_servers.id', '=', 'tenant_databases.server_id')
            ->where('tenant_databases.tenant_id', $tenantId)
            ->select([
                'tenant_databases.tenant_id',
                'tenant_databases.database_name',
                'tenant_databases.app_username',
                'tenant_databases.app_credential_ref',
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

        return new TenantDatabase(
            tenantId: (string) $row['tenant_id'],
            driver: (string) $row['engine'],
            host: (string) $row['host'],
            port: (int) $row['port'],
            database: (string) $row['database_name'],
            username: (string) $row['app_username'],
            credentialRef: (string) $row['app_credential_ref'],
            status: (string) $row['status'],
            schemaVersion: (int) $row['schema_version'],
            credentialVersion: (int) $row['credential_version'],
        );
    }

    private function masterConnection(): string
    {
        return (string) $this->config->get('tenancy.master_connection', 'master');
    }
}
