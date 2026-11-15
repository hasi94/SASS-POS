<?php

declare(strict_types=1);

namespace Flexipos\Platform\Support;

/**
 * Drop one tenant's cached entitlement snapshot (T-016 Ruling 12).
 *
 * `ConfigEntitlementSource`'s implementation is a no-op: there is nothing
 * cached behind the stand-in, so eviction has nothing to do.
 */
interface EvictsEntitlements
{
    public function evict(string $tenantId): void;
}
