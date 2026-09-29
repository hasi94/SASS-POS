<?php

namespace Flexipos\Kernel\Tenancy;

use Flexipos\Kernel\Tenancy\Contracts\TenantDatabaseRepository;

/**
 * The T-003 mapping source: whatever `config('tenancy.databases')` holds, or an
 * explicit set of records in a test.
 *
 * No migration, no Eloquent model, no query (AC-25). The real master schema is
 * FP-203; until then this is the only implementation, and it is what proves the
 * contract is sufficient before a table exists to satisfy it.
 */
final class InMemoryTenantDatabaseRepository implements TenantDatabaseRepository
{
    /**
     * @var array<string, TenantDatabase>
     */
    private array $records = [];

    /**
     * @param  iterable<TenantDatabase>  $records
     */
    public function __construct(iterable $records = [])
    {
        foreach ($records as $record) {
            $this->records[$record->tenantId] = $record;
        }
    }

    /**
     * Build from the config/tenancy.php `databases` map: tenant identifier =>
     * attributes.
     *
     * A `password` key is not read, because the contract has nowhere to put one:
     * a mapping carries `credential_ref` and nothing else.
     *
     * @param  array<string, mixed>  $databases
     */
    public static function fromConfig(array $databases): self
    {
        $records = [];

        foreach ($databases as $tenantId => $attributes) {
            $attributes = (array) $attributes;

            $records[] = new TenantDatabase(
                tenantId: (string) $tenantId,
                driver: (string) ($attributes['driver'] ?? 'mysql'),
                host: (string) ($attributes['host'] ?? ''),
                port: (int) ($attributes['port'] ?? 3306),
                database: (string) ($attributes['database'] ?? ''),
                username: (string) ($attributes['username'] ?? ''),
                credentialRef: (string) ($attributes['credential_ref'] ?? ''),
                status: (string) ($attributes['status'] ?? ''),
            );
        }

        return new self($records);
    }

    public function find(string $tenantId): ?TenantDatabase
    {
        return $this->records[$tenantId] ?? null;
    }
}
