<?php

declare(strict_types=1);

namespace Flexipos\Platform\Support;

use Illuminate\Support\Facades\Log;

/**
 * Reads the master schema once per call, on the master connection alone
 * (T-016 AC-32).
 *
 * Plan resolution follows Ruling 6: the current subscription's `plan_id`,
 * otherwise `tenants.plan_id`, otherwise no plan at all. A negative
 * `plan_limits.value` is floored at 0 and logged (Ruling 14) - a typo should
 * not hand out unlimited seats.
 */
final class MasterEntitlementSource implements EntitlementSource
{
    public function __construct(private readonly MasterConnection $master) {}

    public function for(string $tenantId): Entitlements
    {
        $connection = $this->master->get();

        $tenant = $connection->table('tenants')->where('id', $tenantId)->first();

        $subscription = CurrentSubscription::for($connection, $tenantId);

        $planId = $subscription !== null ? $subscription->plan_id : ($tenant !== null ? $tenant->plan_id : null);

        // The flag catalogue and a tenant's own overrides exist whether or
        // not the tenant has a plan at all (Ruling 6, AC-17): a tenant with
        // no plan still resolves every flag from the tenant override and the
        // global default.
        $tenantOverrides = [];

        foreach ($connection->table('tenant_feature_flags')->where('tenant_id', $tenantId)->get() as $row) {
            $tenantOverrides[(string) $row->flag_key] = (bool) $row->enabled;
        }

        $flagCatalogue = [];

        foreach ($connection->table('feature_flags')->get() as $row) {
            $flagCatalogue[(string) $row->key] = (bool) $row->default_enabled;
        }

        if ($planId === null) {
            return new Entitlements(null, [], [], $tenantOverrides, $flagCatalogue);
        }

        $limits = [];

        foreach ($connection->table('plan_limits')->where('plan_id', $planId)->get() as $row) {
            $value = (int) $row->value;

            if ($value < 0) {
                Log::error("Negative plan_limits.value for plan [{$planId}], key [{$row->limit_key}]; treated as 0.");
                $value = 0;
            }

            $limits[(string) $row->limit_key] = $value;
        }

        $planFeatures = [];

        foreach ($connection->table('plan_features')->where('plan_id', $planId)->get() as $row) {
            $planFeatures[(string) $row->feature_key] = $row->value === null ? null : json_decode((string) $row->value, true);
        }

        return new Entitlements((string) $planId, $limits, $planFeatures, $tenantOverrides, $flagCatalogue);
    }
}
