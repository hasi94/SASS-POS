<?php

namespace Flexipos\Kernel\Tenancy;

/**
 * Where one tenant's database lives and whether it may be served (AC-24).
 *
 * The subset of docs/database.md section 4.4's `tenant_databases` +
 * `database_servers` columns the connection plumbing actually needs, and nothing
 * else (decision D-29):
 *
 * | property       | column                                    |
 * |----------------|-------------------------------------------|
 * | tenantId       | tenant_databases.tenant_id                |
 * | driver         | database_servers.engine                   |
 * | host, port     | database_servers.host, .port              |
 * | database       | tenant_databases.database_name            |
 * | username       | tenant_databases.app_username             |
 * | credentialRef  | tenant_databases.app_credential_ref       |
 * | status         | tenant_databases.status                   |
 *
 * `schema_version`, `profile`, `size_bytes` and the rotation/migration
 * bookkeeping are deliberately absent: they belong to FP-205, FP-206 and
 * FP-210.
 *
 * **There is no password property, and there never will be.** The record
 * carries a reference; the value is dereferenced at connect time by a
 * SecretResolver and lives only inside a Secret.
 */
final readonly class TenantDatabase
{
    /**
     * The one status that permits a connection (decision D-26). Every other
     * value docs/database.md section 4.4 enumerates - provisioning, migrating,
     * read_only, suspended, moving, restoring, archived, failed - and every
     * unrecognised value is refused.
     */
    public const STATUS_ACTIVE = 'active';

    public function __construct(
        public string $tenantId,
        public string $driver,
        public string $host,
        public int $port,
        public string $database,
        public string $username,
        public string $credentialRef,
        public string $status,
    ) {}

    /**
     * Deny by default: true for exactly one status, never for an unknown one.
     */
    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
