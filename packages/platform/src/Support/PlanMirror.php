<?php

declare(strict_types=1);

namespace Flexipos\Platform\Support;

use Illuminate\Database\Connection;

/**
 * The only writer of `tenants.plan_id` (T-016 D-11, AC-15).
 *
 * Called by `StartSubscription` and `ChangeSubscriptionPlan`, inside their
 * own transaction, immediately after the subscription row they just wrote or
 * changed names the plan `tenants.plan_id` should mirror.
 */
final class PlanMirror
{
    public static function write(Connection $master, string $tenantId, string $planId): void
    {
        $master->table('tenants')->where('id', $tenantId)->update(['plan_id' => $planId]);
    }
}
