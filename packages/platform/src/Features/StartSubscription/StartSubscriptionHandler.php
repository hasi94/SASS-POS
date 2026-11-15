<?php

declare(strict_types=1);

namespace Flexipos\Platform\Features\StartSubscription;

use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Platform\Exceptions\PlatformRefused;
use Flexipos\Platform\Support\EvictsEntitlements;
use Flexipos\Platform\Support\MasterConnection;
use Flexipos\Platform\Support\PlanMirror;
use Flexipos\Platform\Support\PlatformAudit;
use Flexipos\Platform\Support\SubscriptionStatus;
use Flexipos\Platform\Support\TenantStatusSync;
use Illuminate\Support\Str;

/**
 * Start a tenant's first (or next) subscription (T-016 Ruling 18, AC-9 to
 * AC-15).
 *
 * `unit_price` and `currency` are snapshotted from the plan at this instant;
 * `tenants.plan_id` is written by `PlanMirror` and the reconciler runs last,
 * all inside this one transaction (AC-15, AC-29).
 */
final class StartSubscriptionHandler
{
    public function __construct(
        private readonly MasterConnection $master,
        private readonly RunnerClock $clock,
        private readonly PlatformAudit $audit,
        private readonly TenantStatusSync $statusSync,
        private readonly EvictsEntitlements $evictor,
    ) {}

    public function handle(StartSubscription $command): StartSubscriptionResult
    {
        if (! in_array($command->status, [SubscriptionStatus::TRIAL, SubscriptionStatus::ACTIVE], true)) {
            throw PlatformRefused::validation('A subscription may only be started as [trial] or [active].');
        }

        $connection = $this->master->get();

        $result = $connection->transaction(function () use ($connection, $command): StartSubscriptionResult {
            $plan = $connection->table('plans')
                ->where('id', $command->planId)
                ->whereNull('deleted_at')
                ->where('is_active', true)
                ->first();

            if ($plan === null) {
                throw PlatformRefused::notFound("No active plan [{$command->planId}].");
            }

            $now = $this->clock->timestamp();
            $subscriptionId = (string) Str::ulid();

            $connection->table('subscriptions')->insert([
                'id' => $subscriptionId,
                'tenant_id' => $command->tenantId,
                'plan_id' => $command->planId,
                'status' => $command->status,
                'quantity' => 1,
                'unit_price' => $plan->price,
                'currency' => $plan->currency,
                'starts_at' => $now,
                'current_period_start' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            PlanMirror::write($connection, $command->tenantId, $command->planId);

            $this->audit->write(
                $connection,
                'subscription_started',
                'subscription',
                $subscriptionId,
                $command->tenantId,
                $command->actorUserId,
                null,
                ['tenant_id' => $command->tenantId, 'plan_id' => $command->planId, 'status' => $command->status],
            );

            $trigger = $this->statusSync->reconcile($connection, $command->tenantId, $command->actorUserId);

            return new StartSubscriptionResult($subscriptionId, $trigger);
        });

        $this->evictor->evict($command->tenantId);

        return $result;
    }
}
