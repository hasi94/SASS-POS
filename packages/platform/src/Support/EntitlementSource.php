<?php

declare(strict_types=1);

namespace Flexipos\Platform\Support;

/**
 * Where an entitlement snapshot comes from (T-016 Ruling 12).
 */
interface EntitlementSource
{
    public function for(string $tenantId): Entitlements;
}
