<?php

declare(strict_types=1);

namespace Flexipos\Platform\Features\SetTenantFeatureFlag;

use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Platform\Exceptions\PlatformRefused;
use Flexipos\Platform\Support\EvictsEntitlements;
use Flexipos\Platform\Support\MasterConnection;
use Flexipos\Platform\Support\PlatformAudit;

/**
 * Set or clear a tenant-level feature-flag override (T-016 plan section 3).
 *
 * `$enabled === null` clears the override (deletes the row); anything else
 * upserts it. An unknown key is `validation`. The cache is evicted after
 * commit.
 */
final class SetTenantFeatureFlagHandler
{
    public function __construct(
        private readonly MasterConnection $master,
        private readonly RunnerClock $clock,
        private readonly PlatformAudit $audit,
        private readonly EvictsEntitlements $evictor,
    ) {}

    public function handle(SetTenantFeatureFlag $command): void
    {
        $connection = $this->master->get();

        $connection->transaction(function () use ($connection, $command): void {
            $flag = $connection->table('feature_flags')->where('key', $command->flagKey)->first();

            if ($flag === null) {
                throw PlatformRefused::validation("No feature flag [{$command->flagKey}] is declared.");
            }

            $existing = $connection->table('tenant_feature_flags')
                ->where('tenant_id', $command->tenantId)
                ->where('flag_key', $command->flagKey)
                ->first();

            if ($command->enabled === null) {
                $connection->table('tenant_feature_flags')
                    ->where('tenant_id', $command->tenantId)
                    ->where('flag_key', $command->flagKey)
                    ->delete();

                $this->audit->write(
                    $connection,
                    'tenant_feature_flag_cleared',
                    'tenant',
                    $command->tenantId,
                    $command->tenantId,
                    $command->actorUserId,
                    $existing === null ? null : ['enabled' => $existing->enabled],
                    null,
                );

                return;
            }

            $now = $this->clock->timestamp();

            if ($existing === null) {
                $connection->table('tenant_feature_flags')->insert([
                    'tenant_id' => $command->tenantId,
                    'flag_key' => $command->flagKey,
                    'enabled' => $command->enabled,
                    'reason' => $command->reason,
                    'set_by' => $command->actorUserId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } else {
                $connection->table('tenant_feature_flags')
                    ->where('tenant_id', $command->tenantId)
                    ->where('flag_key', $command->flagKey)
                    ->update([
                        'enabled' => $command->enabled,
                        'reason' => $command->reason,
                        'set_by' => $command->actorUserId,
                        'updated_at' => $now,
                    ]);
            }

            $this->audit->write(
                $connection,
                'tenant_feature_flag_set',
                'tenant',
                $command->tenantId,
                $command->tenantId,
                $command->actorUserId,
                $existing === null ? null : ['enabled' => $existing->enabled],
                ['enabled' => $command->enabled],
            );
        });

        $this->evictor->evict($command->tenantId);
    }
}
