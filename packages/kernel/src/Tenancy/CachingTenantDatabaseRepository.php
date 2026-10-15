<?php

namespace Flexipos\Kernel\Tenancy;

use Flexipos\Kernel\Tenancy\Contracts\TenantDatabaseRepository;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * The 300-second window in front of the mapping source (AC-14, AC-17).
 *
 * Every tenant-scoped request reads the mapping, and the mapping changes when a
 * tenant is provisioned, moved or rotated - which is rare enough that a short
 * window costs nothing and saves a master read per request.
 *
 * Four decisions, each of which is a refusal of the obvious alternative:
 *
 * - **The key is the tenant id alone** (`tenant:conn:<tenant_id>`), and the
 *   credential version is carried as a *value* inside the entry. A key that
 *   included the version would leave the old entry behind on a rotation, still
 *   reachable by anything that computed the old key, and nothing would ever
 *   evict it.
 * - **The mapping is cached, never a resolved secret** (AC-15). There is
 *   nothing to be careful about: the record has no password property, so the
 *   entry is built from the reference. The cache store is scanned by a test
 *   anyway, with a planted value as the control.
 * - **A miss is not cached.** A tenant being provisioned has no mapping row for
 *   a few seconds; caching the null would make it unreachable for five minutes
 *   *after* it exists. "No mapping" is a refusal, not a value worth keeping.
 * - **`evict()` takes one tenant and is not a flush.** It is the call the
 *   runner makes in the same step as every status or version change (D-19), and
 *   a flush would make one tenant's migration a cache stampede for every other
 *   shop on the server.
 *
 * The entry is a plain array rather than a serialised object, so an entry
 * written by one deployment is still readable by the next one after the record
 * gains a property - which it just did.
 */
final class CachingTenantDatabaseRepository implements TenantDatabaseRepository
{
    /**
     * The key shape AC-14 pins. One prefix, in one place, so the reader and
     * `evict()` cannot disagree about what a tenant's key is.
     */
    public const KEY_PREFIX = 'tenant:conn:';

    public function __construct(
        private readonly TenantDatabaseRepository $inner,
        private readonly CacheRepository $cache,
        private readonly int $ttl,
    ) {}

    public function find(string $tenantId): ?TenantDatabase
    {
        $entry = $this->cache->get($this->keyFor($tenantId));

        if (is_array($entry)) {
            return $this->fromEntry($entry);
        }

        $record = $this->inner->find($tenantId);

        if (! $record instanceof TenantDatabase) {
            return null;
        }

        $this->cache->put($this->keyFor($tenantId), $this->toEntry($record), $this->ttl);

        return $record;
    }

    /**
     * Drop one tenant's cached mapping (AC-17).
     */
    public function evict(string $tenantId): void
    {
        $this->cache->forget($this->keyFor($tenantId));
    }

    /**
     * The source this decorates.
     *
     * Exposed so that "the shipped configuration binds the master-backed reader
     * behind the cache" is a statement a test can make about the object graph
     * rather than about a container binding's closure, which is where the
     * mapping source has been wrong before without anything noticing.
     */
    public function inner(): TenantDatabaseRepository
    {
        return $this->inner;
    }

    private function keyFor(string $tenantId): string
    {
        return self::KEY_PREFIX.$tenantId;
    }

    /**
     * @return array<string, mixed>
     */
    private function toEntry(TenantDatabase $record): array
    {
        return [
            'tenant_id' => $record->tenantId,
            'driver' => $record->driver,
            'host' => $record->host,
            'port' => $record->port,
            'database' => $record->database,
            'username' => $record->username,
            'credential_ref' => $record->credentialRef,
            'status' => $record->status,
            'schema_version' => $record->schemaVersion,
            'credential_version' => $record->credentialVersion,
        ];
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function fromEntry(array $entry): TenantDatabase
    {
        return new TenantDatabase(
            tenantId: (string) ($entry['tenant_id'] ?? ''),
            driver: (string) ($entry['driver'] ?? ''),
            host: (string) ($entry['host'] ?? ''),
            port: (int) ($entry['port'] ?? 0),
            database: (string) ($entry['database'] ?? ''),
            username: (string) ($entry['username'] ?? ''),
            credentialRef: (string) ($entry['credential_ref'] ?? ''),
            status: (string) ($entry['status'] ?? ''),
            schemaVersion: (int) ($entry['schema_version'] ?? 0),
            credentialVersion: (int) ($entry['credential_version'] ?? 1),
        );
    }
}
