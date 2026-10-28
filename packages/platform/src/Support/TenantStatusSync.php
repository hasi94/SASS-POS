<?php

declare(strict_types=1);

namespace Flexipos\Platform\Support;

use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Platform\Exceptions\PlatformRefused;
use Illuminate\Database\Connection;

/**
 * The only writer of `tenants.status` in this task (T-016 Ruling 4, D-16,
 * AC-29).
 *
 * `reconcile()` always runs inside the caller's own transaction: every
 * subscription or licence write calls it as its last act, and the scheduled
 * sweep calls it once per candidate inside a transaction of its own.
 *
 * **No-op when nothing changes** (AC-13): if the computed target equals the
 * current status, nothing is written and no audit row is added - which is
 * what makes a repeated reconcile idempotent.
 *
 * **Deliberately not `final`.** The sweep's isolation proof needs a double
 * that throws for one named tenant and delegates every other call, bound
 * over this concrete type in a test.
 */
class TenantStatusSync
{
    public function __construct(
        private readonly RunnerClock $clock,
        private readonly PlatformAudit $audit,
        private readonly int $configuredGraceDays,
    ) {}

    /**
     * @return string|null the trigger, if a transition happened; null otherwise
     *
     * @throws PlatformRefused not_found when the tenant does not exist
     */
    public function reconcile(Connection $master, string $tenantId, ?string $actorUserId): ?string
    {
        $tenant = $master->table('tenants')->where('id', $tenantId)->lockForUpdate()->first();

        if ($tenant === null) {
            throw PlatformRefused::notFound("No tenant [{$tenantId}].");
        }

        $currentStatus = (string) $tenant->status;
        $currentSuspensionReason = $tenant->suspension_reason;

        $subscription = CurrentSubscription::for($master, $tenantId);
        $subscriptionStatus = $subscription?->status;

        $gracePeriodExpired = false;

        if ($subscriptionStatus === SubscriptionStatus::PAST_DUE && $subscription->past_due_at !== null) {
            $gracePeriodExpired = GracePeriod::expired(
                new \DateTimeImmutable((string) $subscription->past_due_at),
                $this->clock->now(),
                GracePeriod::days($master, $this->configuredGraceDays),
            );
        }

        $licenceVerdict = PlatformLicence::verdict(
            PlatformLicence::platformRowsFor($master, $tenantId),
            $this->clock->now(),
        );

        $target = CommercialStatus::target(
            $currentStatus,
            $currentSuspensionReason,
            $subscriptionStatus,
            $gracePeriodExpired,
            $licenceVerdict,
        );

        if ($target['status'] === $currentStatus) {
            return null;
        }

        $now = $this->clock->timestamp();
        $updates = ['status' => $target['status'], 'updated_at' => $now];

        if ($target['status'] === TenantCommercialStatus::SUSPENDED) {
            $updates['suspended_at'] = $now;
            $updates['suspension_reason'] = $target['trigger'];
        } elseif ($currentStatus === TenantCommercialStatus::SUSPENDED) {
            $updates['suspended_at'] = null;
            $updates['suspension_reason'] = null;
        }

        $master->table('tenants')->where('id', $tenantId)->update($updates);

        $this->audit->write(
            $master,
            'tenant_status_synced',
            'tenant',
            $tenantId,
            $tenantId,
            $actorUserId,
            ['status' => $currentStatus, 'suspension_reason' => $currentSuspensionReason],
            ['status' => $target['status'], 'suspension_reason' => $updates['suspension_reason'] ?? $currentSuspensionReason, 'trigger' => $target['trigger']],
        );

        return $target['trigger'];
    }
}
