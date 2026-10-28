<?php

declare(strict_types=1);

namespace Flexipos\Platform\Features\EnforceCommercialDeadlines;

use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Platform\Support\GracePeriod;
use Flexipos\Platform\Support\LicenceStatus;
use Flexipos\Platform\Support\MasterConnection;
use Flexipos\Platform\Support\SubscriptionStatus;
use Flexipos\Platform\Support\TenantCommercialStatus;
use Flexipos\Platform\Support\TenantStatusSync;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The one-minute scheduled sweep (T-016 Ruling 2, AC-11, AC-16, AC-25 time
 * half).
 *
 * Nothing changes `tenants.status` at the instant a deadline passes - this
 * is evaluated, at most a minute late, by `platform:enforce-deadlines`.
 *
 * Each candidate reconciles in its **own** transaction: a failure is logged
 * with the tenant id and the exception class, and the sweep continues.
 */
final class EnforceCommercialDeadlinesHandler
{
    public function __construct(
        private readonly MasterConnection $master,
        private readonly RunnerClock $clock,
        private readonly TenantStatusSync $statusSync,
        private readonly int $configuredGraceDays,
    ) {}

    public function handle(EnforceCommercialDeadlines $command): EnforceCommercialDeadlinesResult
    {
        $connection = $this->master->get();
        $now = $this->clock->timestamp();

        // Step 1: stamp any past_due row the previous code never touched.
        $connection->table('subscriptions')
            ->where('status', SubscriptionStatus::PAST_DUE)
            ->whereNull('past_due_at')
            ->update(['past_due_at' => $now]);

        $graceDays = GracePeriod::days($connection, $this->configuredGraceDays);
        $cutoff = $this->clock->now()->modify("-{$graceDays} days")->format('Y-m-d H:i:s.v');

        $candidatesA = $connection->table('subscriptions')
            ->join('tenants', 'tenants.id', '=', 'subscriptions.tenant_id')
            ->where('subscriptions.status', SubscriptionStatus::PAST_DUE)
            ->whereNotNull('subscriptions.past_due_at')
            ->where('subscriptions.past_due_at', '<=', $cutoff)
            ->where('tenants.status', '!=', TenantCommercialStatus::SUSPENDED)
            ->pluck('subscriptions.tenant_id');

        $candidatesB = $connection->table('licences')
            ->join('tenants', 'tenants.id', '=', 'licences.tenant_id')
            ->where('licences.type', LicenceStatus::PLATFORM_TYPE)
            ->whereNotNull('licences.valid_until')
            ->where('licences.valid_until', '<=', $now)
            ->where('tenants.status', '!=', TenantCommercialStatus::SUSPENDED)
            ->pluck('licences.tenant_id');

        $candidatesC = $connection->table('tenants')
            ->where('status', TenantCommercialStatus::SUSPENDED)
            ->where('suspension_reason', 'licence_lapsed')
            ->pluck('id');

        $candidates = array_unique(array_values(array_merge(
            $candidatesA->all(),
            $candidatesB->all(),
            $candidatesC->all(),
        )));

        $examined = count($candidates);
        $changed = 0;
        $failed = 0;

        foreach ($candidates as $tenantId) {
            try {
                $trigger = $connection->transaction(
                    fn () => $this->statusSync->reconcile($connection, (string) $tenantId, null)
                );

                if ($trigger !== null) {
                    $changed++;
                }
            } catch (Throwable $exception) {
                $failed++;

                Log::error("platform:enforce-deadlines failed for tenant [{$tenantId}]: ".$exception::class);
            }
        }

        return new EnforceCommercialDeadlinesResult($examined, $changed, $failed);
    }
}
