<?php

declare(strict_types=1);

namespace Flexipos\Platform\Features\ChangeSubscriptionStatus;

use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Platform\Exceptions\PlatformRefused;
use Flexipos\Platform\Support\MasterConnection;
use Flexipos\Platform\Support\PlatformAudit;
use Flexipos\Platform\Support\SubscriptionStatus;
use Flexipos\Platform\Support\TenantStatusSync;

/**
 * Move a subscription to a new status (T-016 Ruling 18, AC-9 to AC-14, AC-16).
 *
 * A move to the same status is refused `validation` (T-014 Ruling 10(f)). A
 * change on a non-current row is allowed; `TenantStatusSync` recomputes the
 * current row itself, so reconciling after a historical write is naturally a
 * no-op when it should be (AC-14).
 */
final class ChangeSubscriptionStatusHandler
{
    public function __construct(
        private readonly MasterConnection $master,
        private readonly RunnerClock $clock,
        private readonly PlatformAudit $audit,
        private readonly TenantStatusSync $statusSync,
    ) {}

    public function handle(ChangeSubscriptionStatus $command): ChangeSubscriptionStatusResult
    {
        if (! in_array($command->status, SubscriptionStatus::ALL, true)) {
            throw PlatformRefused::validation("Unknown subscription status [{$command->status}].");
        }

        $connection = $this->master->get();

        return $connection->transaction(function () use ($connection, $command): ChangeSubscriptionStatusResult {
            $subscription = $connection->table('subscriptions')
                ->where('id', $command->subscriptionId)
                ->lockForUpdate()
                ->first();

            if ($subscription === null) {
                throw PlatformRefused::notFound("No subscription [{$command->subscriptionId}].");
            }

            if ((string) $subscription->status === $command->status) {
                throw PlatformRefused::validation('The subscription is already in that status.');
            }

            $now = $this->clock->timestamp();
            $updates = ['status' => $command->status, 'updated_at' => $now];

            if ($command->status === SubscriptionStatus::CANCELLED) {
                $updates['cancelled_at'] = $now;
                $updates['cancellation_reason'] = $command->cancellationReason;
            } elseif ((string) $subscription->status === SubscriptionStatus::CANCELLED) {
                // Review S-4: leaving `cancelled` clears the record of why it
                // was cancelled - a reactivated subscription is not still
                // bearing a cancellation.
                $updates['cancelled_at'] = null;
                $updates['cancellation_reason'] = null;
            }

            if ($command->status === SubscriptionStatus::PAST_DUE && (string) $subscription->status !== SubscriptionStatus::PAST_DUE) {
                $updates['past_due_at'] = $now;
            } elseif ((string) $subscription->status === SubscriptionStatus::PAST_DUE && $command->status !== SubscriptionStatus::PAST_DUE) {
                $updates['past_due_at'] = null;
            }

            $connection->table('subscriptions')->where('id', $command->subscriptionId)->update($updates);

            $this->audit->write(
                $connection,
                'subscription_status_changed',
                'subscription',
                $command->subscriptionId,
                (string) $subscription->tenant_id,
                $command->actorUserId,
                ['status' => $subscription->status],
                ['status' => $command->status],
            );

            $trigger = $this->statusSync->reconcile($connection, (string) $subscription->tenant_id, $command->actorUserId);

            return new ChangeSubscriptionStatusResult($command->subscriptionId, $trigger);
        });
    }
}
