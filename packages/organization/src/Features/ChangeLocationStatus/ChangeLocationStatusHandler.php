<?php

declare(strict_types=1);

namespace Flexipos\Organization\Features\ChangeLocationStatus;

use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Contracts\Platform\LimitKey;
use Flexipos\Contracts\Platform\PlanLimits;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Organization\Exceptions\OrganizationRefused;
use Flexipos\Organization\Registry\OrganizationPermissions;
use Flexipos\Organization\Support\LocationLookup;
use Flexipos\Organization\Support\PlanCounts;
use Flexipos\Organization\Support\TenantConnection;

/**
 * Deactivate or reactivate a location (T-014 AC-24, D-14).
 *
 * **Deactivation is refused `location_in_use` for either of two causes**: the
 * location is the `default_location_id` of an **active** branch (the default
 * store of an inactive branch deactivates), or it is the selling location of a
 * `pending` or `active` terminal. **Stock on hand is not checked**: no stock
 * exists yet, and FP-251 must add its own refusal - that is stated here and
 * not tested.
 *
 * **Reactivating a location whose branch is inactive is refused
 * `branch_inactive`**: a location cannot be switched on under a branch that is
 * switched off. A null-branch warehouse has no such condition. Doing either
 * twice is `validation` and writes no audit row.
 */
final class ChangeLocationStatusHandler
{
    public const ACTION_DEACTIVATED = 'location_deactivated';

    public const ACTION_REACTIVATED = 'location_reactivated';

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly AuditTrail $audit,
        private readonly PlanLimits $planLimits,
    ) {}

    public function handle(ChangeLocationStatus $command): void
    {
        if (! is_bool($command->isActive)) {
            throw OrganizationRefused::validation('The is_active must be true or false.');
        }

        $wanted = $command->isActive;
        $connection = $this->tenant->get();

        $connection->transaction(function () use ($connection, $command, $wanted): void {
            $location = LocationLookup::find($connection, $command->locationId, lock: true);
            $id = (string) $location->id;

            if ((bool) $location->is_active === $wanted) {
                throw OrganizationRefused::validation('The location is already '.($wanted ? 'active' : 'inactive').'.');
            }

            if (! $wanted) {
                $defaultOfActiveBranch = $connection->table('branches')
                    ->where('default_location_id', $id)
                    ->where('is_active', true)
                    ->whereNull('deleted_at')
                    ->exists();

                $sellsFrom = $connection->table('terminals')
                    ->where('location_id', $id)
                    ->whereNull('deleted_at')
                    ->whereIn('status', ['pending', 'active'])
                    ->exists();

                if ($defaultOfActiveBranch || $sellsFrom) {
                    throw OrganizationRefused::locationInUse(
                        "Location [{$id}] is ".($defaultOfActiveBranch ? 'the default store of an active branch' : 'the selling location of a pending or active terminal').'.'
                    );
                }
            } elseif ($location->branch_id !== null) {
                $active = $connection->table('branches')
                    ->where('id', (string) $location->branch_id)
                    ->whereNull('deleted_at')
                    ->value('is_active');

                if (! (bool) $active) {
                    throw OrganizationRefused::branchInactive("The branch of location [{$id}] is not active.");
                }
            }

            if ($wanted) {
                // T-016 AC-2a, D-20: reactivation spends quota exactly as
                // creation does.
                $this->planLimits->forCurrentTenant(LimitKey::Locations)
                    ->assertRoomFor(PlanCounts::activeLocations($connection));
            }

            $connection->table('locations')->where('id', $id)->update([
                'is_active' => $wanted,
                'updated_by' => $command->actorId,
                'updated_at' => $this->clock->timestamp(),
            ]);

            $this->audit->record(
                OrganizationPermissions::MODULE,
                $command->actorId,
                $wanted ? self::ACTION_REACTIVATED : self::ACTION_DEACTIVATED,
                'location',
                $id,
                ['is_active' => ! $wanted],
                ['is_active' => $wanted],
                $location->branch_id === null ? null : (string) $location->branch_id,
            );
        });
    }
}
