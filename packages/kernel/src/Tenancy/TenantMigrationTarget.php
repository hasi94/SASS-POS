<?php

namespace Flexipos\Kernel\Tenancy;

/**
 * Where one tenant's database lives, for the runner, with the **migrate**
 * credential reference (AC-18's control object).
 *
 * A separate type from TenantDatabase rather than two more properties on it.
 * The reason is the whole of AC-18: the record a request resolves must not be
 * able to carry a migrate username or a migrate credential reference at all,
 * and "cannot carry" is a property of a class, not of a code path that happens
 * not to fill two fields in.
 *
 * It still holds **no password**. Like the serving record it carries a
 * reference, which a SecretResolver dereferences at connect time, and the value
 * only ever exists inside a Secret.
 *
 * `asConnectionRecord()` is how the runner's target reaches the one
 * connection-construction path. It yields a TenantDatabase whose username and
 * credential reference are the *migrate* pair, which is what a migration scope
 * must connect as, and it is the only place the two namings meet. The result is
 * never handed to a request: Tenancy builds it inside enter() for the Migrating
 * purpose, nothing else can reach it, and a Serving entry is built from the
 * serving repository, whose read does not even select the migrate columns.
 */
final readonly class TenantMigrationTarget
{
    public function __construct(
        public string $tenantId,
        public string $driver,
        public string $host,
        public int $port,
        public string $database,
        public string $migrateUsername,
        public string $migrateCredentialRef,
        public string $status,
        public int $schemaVersion = 0,
        public int $credentialVersion = 1,
    ) {}

    /**
     * The same target as a connection record, carrying the migrate pair in the
     * fields a connection is built from.
     */
    public function asConnectionRecord(): TenantDatabase
    {
        return new TenantDatabase(
            tenantId: $this->tenantId,
            driver: $this->driver,
            host: $this->host,
            port: $this->port,
            database: $this->database,
            username: $this->migrateUsername,
            credentialRef: $this->migrateCredentialRef,
            status: $this->status,
            schemaVersion: $this->schemaVersion,
            credentialVersion: $this->credentialVersion,
        );
    }

    /**
     * The one status that permits a migration connection, which is the same one
     * that permits a serving connection.
     *
     * Status rules are not waived by the migration purpose: a tenant that is
     * suspended, moving or restoring is not migrated either, and the runner's
     * own planning filters on status before a job row is written (AC-51).
     */
    public function isActive(): bool
    {
        return $this->status === TenantDatabase::STATUS_ACTIVE;
    }
}
