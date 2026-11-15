<?php

declare(strict_types=1);

namespace Flexipos\Platform\Provisioning\Steps;

use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Kernel\Tenancy\Contracts\EvictsTenantMappings;
use Flexipos\Kernel\Tenancy\Contracts\TenantDatabaseRepository;
use Flexipos\Kernel\Tenancy\Provisioning\TenantMappingWriter;
use Flexipos\Platform\Features\StartSubscription\StartSubscription;
use Flexipos\Platform\Features\StartSubscription\StartSubscriptionHandler;
use Flexipos\Platform\Provisioning\ProvisioningStep;
use Flexipos\Platform\Provisioning\RunContext;
use Flexipos\Platform\Provisioning\StepJournal;
use Flexipos\Platform\Provisioning\StepName;
use Flexipos\Platform\Support\EvictsEntitlements;
use Flexipos\Platform\Support\MasterConnection;
use Flexipos\Platform\Support\SubscriptionStatus;

/**
 * `activate`, the last of the eleven steps (T-026 Ruling 9, AC-33 to AC-36).
 *
 * **One master transaction, in Ruling 9's order:**
 *
 * 1. {@see TenantMappingWriter::activate()} - `provisioning` → `active`,
 *    compare-and-set (AC-33). Its own eviction fires here, inside the
 *    transaction, and is premature by itself (Ruling 9) - the same thing
 *    {@see StartSubscriptionHandler}'s own eviction call does when nested.
 * 2. Only when that write actually happened (this call, not an earlier one):
 *    `database_servers.tenant_count` is incremented by exactly one - the
 *    first and only place it moves (AC-14, AC-33).
 * 3. {@see StartSubscriptionHandler}, skipped when a subscription row
 *    already exists for this tenant (AC-35). Its own transaction nests as a
 *    savepoint.
 * 4. `tenants.status: 'provisioning' → 'trial'|'active'`, compare-and-set on
 *    `status = 'provisioning'` (AC-34, D-7, D-8) - the one explicit
 *    transition `TenantStatusSync` declines to make for a protected status.
 *
 * **After commit** (`$connection->afterCommit()`), the mapping and
 * entitlement caches are evicted again - the real eviction this step cares
 * about, run once the whole transaction has actually landed, never only the
 * premature one nested inside it.
 *
 * **AC-35: re-entry writes nothing.** Every write above is itself
 * compare-and-set or existence-gated, so a second call for an already-active
 * tenant affects zero rows throughout and only re-runs the (harmless,
 * idempotent) cache eviction.
 *
 * This step does not open a tenant connection, so Ruling 6's pre-check does
 * not apply to it - the two writes it makes are read guards in their own
 * right (`WHERE status = 'provisioning'`).
 */
final class Activate implements ProvisioningStep
{
    public function __construct(
        private readonly MasterConnection $master,
        private readonly TenantMappingWriter $mappingWriter,
        private readonly StartSubscriptionHandler $startSubscription,
        private readonly RunnerClock $clock,
        private readonly TenantDatabaseRepository $mappings,
        private readonly EvictsEntitlements $entitlements,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function run(RunContext $context, StepJournal $journal): array
    {
        $reserved = $journal->outputOf(StepName::ReserveIdentity);
        $tenantId = (string) $reserved['tenant_id'];

        $server = $journal->outputOf(StepName::SelectServer);
        $serverId = (string) $server['server_id'];

        $planId = (string) $context->input['plan_id'];
        $active = (bool) ($context->input['active'] ?? false);

        $master = $this->master->get();

        $master->transaction(function () use ($master, $tenantId, $serverId, $planId, $active, $context): void {
            $activated = $this->mappingWriter->activate($tenantId);

            if ($activated) {
                $master->table('database_servers')->where('id', $serverId)->increment('tenant_count');
            }

            $hasSubscription = $master->table('subscriptions')->where('tenant_id', $tenantId)->exists();

            if (! $hasSubscription) {
                $this->startSubscription->handle(new StartSubscription(
                    tenantId: $tenantId,
                    planId: $planId,
                    status: $active ? SubscriptionStatus::ACTIVE : SubscriptionStatus::TRIAL,
                    actorUserId: $context->initiatedBy,
                ));
            }

            $now = $this->clock->timestamp();

            $master->table('tenants')
                ->where('id', $tenantId)
                ->where('status', 'provisioning')
                ->update([
                    'status' => $active ? 'active' : 'trial',
                    'activated_at' => $now,
                    'updated_at' => $now,
                ]);

            $master->afterCommit(function () use ($tenantId): void {
                $this->evictMapping($tenantId);
                $this->entitlements->evict($tenantId);
            });
        });

        return [];
    }

    public function compensate(RunContext $context): ?string
    {
        // abandon only ever compensates a failed/stale run (Ruling 10), and
        // this is the last step: by the time it has run once successfully
        // the whole run is `completed`, which abandon already refuses to
        // touch.
        return null;
    }

    /**
     * Asked by capability, never by class - the same precedent
     * {@see TenantMappingWriter}'s own private evict() follows: the suite
     * binds the in-memory reader, a deployment binds the caching decorator,
     * and only the latter has anything to evict.
     */
    private function evictMapping(string $tenantId): void
    {
        if ($this->mappings instanceof EvictsTenantMappings) {
            $this->mappings->evict($tenantId);
        }
    }
}
