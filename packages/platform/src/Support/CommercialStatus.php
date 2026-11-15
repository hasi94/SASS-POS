<?php

declare(strict_types=1);

namespace Flexipos\Platform\Support;

/**
 * The reconciliation rule, in full (T-016 Ruling 4, AC-9 to AC-13, AC-25).
 *
 * Pure: no query, no clock read, no write. `TenantStatusSync` gathers every
 * input and this class alone decides. The first matching row wins.
 */
final class CommercialStatus
{
    /**
     * @return array{status: string, trigger: ?string}
     */
    public static function target(
        string $currentStatus,
        ?string $currentSuspensionReason,
        ?string $subscriptionStatus,
        bool $gracePeriodExpired,
        string $licenceVerdict,
    ): array {
        // Row 0: protected statuses, and a suspension this task did not impose.
        if (in_array($currentStatus, TenantCommercialStatus::PROTECTED, true)) {
            return ['status' => $currentStatus, 'trigger' => null];
        }

        if ($currentStatus === TenantCommercialStatus::SUSPENDED
            && ! in_array($currentSuspensionReason, TenantCommercialStatus::SENTINELS, true)) {
            return ['status' => $currentStatus, 'trigger' => null];
        }

        // Row 1.
        if ($subscriptionStatus === SubscriptionStatus::CANCELLED || $subscriptionStatus === SubscriptionStatus::EXPIRED) {
            return ['status' => TenantCommercialStatus::SUSPENDED, 'trigger' => 'subscription_lapsed'];
        }

        // Row 2.
        if ($subscriptionStatus === SubscriptionStatus::PAST_DUE && $gracePeriodExpired) {
            return ['status' => TenantCommercialStatus::SUSPENDED, 'trigger' => 'past_due_grace_expired'];
        }

        // Row 3.
        if ($licenceVerdict === PlatformLicence::LAPSED) {
            return ['status' => TenantCommercialStatus::SUSPENDED, 'trigger' => 'licence_lapsed'];
        }

        // Row 4.
        if ($subscriptionStatus === SubscriptionStatus::PAUSED) {
            return ['status' => TenantCommercialStatus::READ_ONLY, 'trigger' => 'subscription_paused'];
        }

        // Row 5.
        if ($subscriptionStatus === SubscriptionStatus::PAST_DUE) {
            return ['status' => TenantCommercialStatus::PAST_DUE, 'trigger' => 'subscription_past_due'];
        }

        // Row 6.
        if ($subscriptionStatus === SubscriptionStatus::ACTIVE) {
            return ['status' => TenantCommercialStatus::ACTIVE, 'trigger' => 'subscription_active'];
        }

        // Row 7.
        if ($subscriptionStatus === SubscriptionStatus::TRIAL) {
            return ['status' => TenantCommercialStatus::TRIAL, 'trigger' => 'subscription_trial'];
        }

        // Row 8: no subscription row.
        return ['status' => $currentStatus, 'trigger' => null];
    }
}
