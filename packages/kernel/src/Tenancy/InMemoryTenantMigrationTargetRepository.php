<?php

namespace Flexipos\Kernel\Tenancy;

use Flexipos\Kernel\Tenancy\Contracts\TenantMigrationTargetRepository;

/**
 * The local stand-in for the runner's mapping source: whatever
 * `config('tenancy.migration_targets')` holds.
 *
 * The mirror of InMemoryTenantDatabaseRepository, and it exists for the same
 * reason: the two sources are selected by one key
 * (`tenancy.mapping.source`), so a fixture that uses the configuration source
 * gets a *pair* of stand-ins rather than a stand-in for serving and a master
 * schema for migrating. Without it, proving AC-19's exemption - the runner
 * enters a tenant at the required version minus one and succeeds - would need
 * seventeen master migrations and a mapping row to open one SQLite file.
 *
 * A `password` key is not read, because the record has nowhere to put one: a
 * target carries `migrate_credential_ref` and nothing else.
 */
final class InMemoryTenantMigrationTargetRepository implements TenantMigrationTargetRepository
{
    /**
     * @var array<string, TenantMigrationTarget>
     */
    private array $targets = [];

    /**
     * @param  iterable<TenantMigrationTarget>  $targets
     */
    public function __construct(iterable $targets = [])
    {
        foreach ($targets as $target) {
            $this->targets[$target->tenantId] = $target;
        }
    }

    /**
     * Build from the config map: tenant identifier => attributes.
     *
     * @param  array<string, mixed>  $targets
     */
    public static function fromConfig(array $targets): self
    {
        $records = [];

        foreach ($targets as $tenantId => $attributes) {
            $attributes = (array) $attributes;

            $records[] = new TenantMigrationTarget(
                tenantId: (string) $tenantId,
                driver: (string) ($attributes['driver'] ?? 'mysql'),
                host: (string) ($attributes['host'] ?? ''),
                port: (int) ($attributes['port'] ?? 3306),
                database: (string) ($attributes['database'] ?? ''),
                migrateUsername: (string) ($attributes['migrate_username'] ?? ''),
                migrateCredentialRef: (string) ($attributes['migrate_credential_ref'] ?? ''),
                status: (string) ($attributes['status'] ?? ''),
                schemaVersion: (int) ($attributes['schema_version'] ?? 0),
                credentialVersion: (int) ($attributes['credential_version'] ?? 1),
            );
        }

        return new self($records);
    }

    public function find(string $tenantId): ?TenantMigrationTarget
    {
        return $this->targets[$tenantId] ?? null;
    }
}
