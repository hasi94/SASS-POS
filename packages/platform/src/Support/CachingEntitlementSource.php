<?php

declare(strict_types=1);

namespace Flexipos\Platform\Support;

use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * The 300-second window in front of the master entitlement source (T-016
 * Ruling 12, AC-5), shaped exactly like the kernel's
 * `CachingTenantDatabaseRepository`: a plain-array cache entry, a key
 * carrying the tenant id, and a miss that reads the inner source once and
 * fills the cache.
 */
final class CachingEntitlementSource implements EntitlementSource, EvictsEntitlements
{
    public const KEY_PREFIX = 'platform:entitlements:';

    public function __construct(
        private readonly EntitlementSource $inner,
        private readonly CacheRepository $cache,
        private readonly int $ttl,
    ) {}

    public function for(string $tenantId): Entitlements
    {
        $entry = $this->cache->get($this->keyFor($tenantId));

        if (is_array($entry)) {
            return Entitlements::fromArray($entry);
        }

        $entitlements = $this->inner->for($tenantId);

        $this->cache->put($this->keyFor($tenantId), $entitlements->toArray(), $this->ttl);

        return $entitlements;
    }

    public function evict(string $tenantId): void
    {
        $this->cache->forget($this->keyFor($tenantId));
    }

    public function inner(): EntitlementSource
    {
        return $this->inner;
    }

    private function keyFor(string $tenantId): string
    {
        return self::KEY_PREFIX.$tenantId;
    }
}
