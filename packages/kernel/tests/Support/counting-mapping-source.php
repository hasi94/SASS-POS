<?php

use Flexipos\Kernel\Tenancy\Contracts\TenantDatabaseRepository;
use Flexipos\Kernel\Tenancy\TenantDatabase;

/*
 * T-008 step 6: a mapping source that answers from memory and counts what it
 * was asked.
 *
 * A named class in a required support file rather than an anonymous class
 * inside a test, for two reasons: the call counter is a property, so an
 * anonymous class would have to be returned without a declared type - which the
 * analyser refuses - and more than one step needs a counting source (the cache
 * window here, eviction in the runner later). The file is required explicitly
 * by every test that uses it, never relied on having been collected first
 * (FP-164).
 *
 * "The master receives no query for it" is a claim about how many times the
 * *source* is consulted. Counting calls to the source states that directly;
 * counting SQL statements would also pass for a reader that cached the
 * connection instead of the mapping.
 */
final class CountingTenantMappingSource implements TenantDatabaseRepository
{
    /**
     * How many times each tenant was asked for, keyed by identifier.
     *
     * @var array<string, int>
     */
    public array $calls = [];

    /**
     * Identifiers this source has no mapping for, so the "no mapping" branch
     * has an input and is not merely described.
     *
     * @var list<string>
     */
    public array $unmapped = ['tenant-missing'];

    public function find(string $tenantId): ?TenantDatabase
    {
        $this->calls[$tenantId] = ($this->calls[$tenantId] ?? 0) + 1;

        if (in_array($tenantId, $this->unmapped, true)) {
            return null;
        }

        return new TenantDatabase(
            tenantId: $tenantId,
            driver: 'mariadb',
            host: 'db-fixture-host',
            port: 3307,
            database: 'flx_t_'.$tenantId,
            username: 'flx_app_'.$tenantId,
            credentialRef: 'env://CACHE_FIXTURE_PASSWORD',
            status: 'active',
            schemaVersion: 6,
            credentialVersion: 4,
        );
    }
}
