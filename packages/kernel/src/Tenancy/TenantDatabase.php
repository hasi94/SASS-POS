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
 * | schemaVersion  | tenant_databases.schema_version           |
 * | credentialVersion | tenant_databases.credential_version    |
 *
 * `profile`, `size_bytes` and the provisioning bookkeeping are deliberately
 * absent: they belong to FP-206 and FP-210.
 *
 * The last two properties arrived with FP-205 (T-008 step 6) and are a
 * deliberate contract change, not a widening: `schemaVersion` is what the
 * serving version gate compares (AC-19), and `credentialVersion` is what makes
 * a credential rotation invalidate a cached mapping by construction (AC-14,
 * AC-16). Both are integers, both have defaults so no existing call site
 * changed, and neither can hold a secret - which the sibling check on property
 * names still asserts over both of them.
 *
 * **There is still no migrate username and no migrate credential reference**
 * (AC-18). The row the mapping is read from carries them; this record does not,
 * and the runner's own `TenantMigrationTarget` is the only type that does.
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
        /**
         * The tenant's position in the migration catalogue, mirrored from the
         * tenant's own `migrations` table (D-14). Zero means "nothing applied
         * yet", which is a tenant the runner migrates rather than one anything
         * serves.
         */
        public int $schemaVersion = 0,
        /**
         * Bumped by a credential rotation. It is carried as a *value* inside
         * the cache entry rather than as part of the cache key, so a rotation
         * cannot leave an old entry behind under a key something still
         * computes.
         */
        public int $credentialVersion = 1,
    ) {}

    /**
     * Deny by default: true for exactly one status, never for an unknown one.
     */
    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
