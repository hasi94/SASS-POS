<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Platform;

/**
 * The `users` limit key - master-side seats, `tenant_users` rows, not tenant
 * employees (T-016 Ruling 9, AC-8).
 *
 * Takes the tenant id explicitly, unlike `PlanLimits::forCurrentTenant()`:
 * there is no invite or access-grant flow yet for this to sit inside a
 * current-tenant request, so the first caller (a later task) passes whichever
 * tenant it is granting a seat on.
 */
interface TenantSeats
{
    /**
     * @throws PlanLimitReached
     */
    public function ensureRoomForOneMore(string $tenantId): void;
}
