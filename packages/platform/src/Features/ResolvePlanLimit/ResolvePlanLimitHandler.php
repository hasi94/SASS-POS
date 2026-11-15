<?php

declare(strict_types=1);

namespace Flexipos\Platform\Features\ResolvePlanLimit;

use Flexipos\Contracts\Platform\LimitKey;
use Flexipos\Contracts\Platform\PlanLimit;
use Flexipos\Contracts\Platform\PlanLimits;
use Flexipos\Kernel\Tenancy\TenantContext;
use Flexipos\Platform\Support\EntitlementSource;

/**
 * Implements `PlanLimits` (T-016 AC-3, AC-5).
 *
 * Reads the tenant id from `TenantContext` and never from a caller argument:
 * with no tenant resolved it throws `NoTenantResolved` and never returns an
 * uncapped answer by accident.
 */
final class ResolvePlanLimitHandler implements PlanLimits
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly EntitlementSource $source,
    ) {}

    public function forCurrentTenant(LimitKey $key): PlanLimit
    {
        $tenantId = $this->tenantContext->tenantId();

        return $this->handle(new ResolvePlanLimit($key), $tenantId);
    }

    public function handle(ResolvePlanLimit $command, string $tenantId): PlanLimit
    {
        return $this->source->for($tenantId)->limit($command->key);
    }
}
