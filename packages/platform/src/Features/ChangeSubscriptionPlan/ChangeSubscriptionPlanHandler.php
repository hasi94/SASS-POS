<?php

declare(strict_types=1);

namespace Flexipos\Platform\Features\ChangeSubscriptionPlan;

use Flexipos\Platform\Exceptions\PlatformRefused;
use Flexipos\Platform\Support\CurrentSubscription;
use Flexipos\Platform\Support\EvictsEntitlements;
use Flexipos\Platform\Support\MasterConnection;
use Flexipos\Platform\Support\PlanMirror;
use Flexipos\Platform\Support\PlatformAudit;

/**
 * Change the plan of a tenant's **current** subscription (T-016 Ruling 18,
 * AC-15).
 *
 * Refused `not_current` on a historical row, because `tenants.plan_id`
 * mirrors only the current row. Writes `plan_id` alone - price and proration
 * are billing, out of scope. The cache is evicted only after commit, never on
 * a rolled-back transaction (AC-3's control).
 */
final class ChangeSubscriptionPlanHandler
{
    public function __construct(
        private readonly MasterConnection $master,
        private readonly PlatformAudit $audit,
        private readonly EvictsEntitlements $evictor,
    ) {}

    public function handle(ChangeSubscriptionPlan $command): ChangeSubscriptionPlanResult
    {
        $connection = $this->master->get();
        $tenantId = null;

        $result = $connection->transaction(function () use ($connection, $command, &$tenantId): ChangeSubscriptionPlanResult {
            $subscription = $connection->table('subscriptions')
                ->where('id', $command->subscriptionId)
                ->lockForUpdate()
                ->first();

            if ($subscription === null) {
                throw PlatformRefused::notFound("No subscription [{$command->subscriptionId}].");
            }

            $tenantId = (string) $subscription->tenant_id;

            $current = CurrentSubscription::for($connection, (string) $subscription->tenant_id);

            if ($current === null || (string) $current->id !== (string) $subscription->id) {
                throw PlatformRefused::notCurrent('Only the tenant\'s current subscription may change plan.');
            }

            $plan = $connection->table('plans')
                ->where('id', $command->planId)
                ->whereNull('deleted_at')
                ->where('is_active', true)
                ->first();

            if ($plan === null) {
                throw PlatformRefused::notFound("No active plan [{$command->planId}].");
            }

            $connection->table('subscriptions')->where('id', $command->subscriptionId)->update(['plan_id' => $command->planId]);

            PlanMirror::write($connection, (string) $subscription->tenant_id, $command->planId);

            $this->audit->write(
                $connection,
                'subscription_plan_changed',
                'subscription',
                $command->subscriptionId,
                (string) $subscription->tenant_id,
                $command->actorUserId,
                ['plan_id' => $subscription->plan_id],
                ['plan_id' => $command->planId],
            );

            return new ChangeSubscriptionPlanResult($command->subscriptionId, $command->planId);
        });

        if ($tenantId !== null) {
            $this->evictor->evict($tenantId);
        }

        return $result;
    }
}
