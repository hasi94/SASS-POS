<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Platform;

/**
 * The one question any package asks before creating or reactivating a
 * plan-limited resource (T-016 Ruling 12, AC-5).
 *
 * The tenant id is read from the kernel's `TenantContext`, never taken as a
 * caller argument (mirrors T-009 AC-45's rule): a caller handed the tenant id
 * as a parameter could ask about a tenant it was never entered into.
 */
interface PlanLimits
{
    /**
     * Throws the kernel's own "no tenant resolved" exception when nothing has
     * entered a tenant yet. Not named here by type: a contract may not import
     * the kernel's internals, even in a docblock.
     */
    public function forCurrentTenant(LimitKey $key): PlanLimit;
}
