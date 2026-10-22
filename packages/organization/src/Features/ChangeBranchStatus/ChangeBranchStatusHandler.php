<?php

declare(strict_types=1);

namespace Flexipos\Organization\Features\ChangeBranchStatus;

use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Organization\Exceptions\OrganizationRefused;
use Flexipos\Organization\Registry\OrganizationPermissions;
use Flexipos\Organization\Support\BranchLookup;
use Flexipos\Organization\Support\LastBranch;
use Flexipos\Organization\Support\TenantConnection;

/**
 * Deactivate or reactivate a branch (T-014 AC-14, D-14).
 *
 * **The order of the refusals is the order of the questions.** The branch
 * exists (`not_found`); there is a state to change (`validation`, writing no
 * audit row: deactivating twice is a request that changed nothing); no
 * `pending` or `active` terminal belongs to it (`has_active_terminals`: an
 * `inactive` or `revoked` one does not block); and it is not the only active
 * branch (`last_branch`).
 *
 * **The last-branch guard reads the active branches `for update`, the way
 * `LastOwner` reads owners**, so two requests deactivating the last two
 * branches take turns instead of both seeing "another one remains". On the
 * SQLite stand-in the lock clause is a no-op; the concurrent half is
 * `[ENGINE]` (AC-16) and stays NOT VERIFIED while the hold stands.
 *
 * Deactivation touches no employee's `perm_version` and no token's claims
 * (D-14): it takes effect where branches are used - attaching, assigning,
 * creating a terminal, writing a setting.
 */
final class ChangeBranchStatusHandler
{
    public const ACTION_DEACTIVATED = 'branch_deactivated';

    public const ACTION_REACTIVATED = 'branch_reactivated';

    /** The terminal statuses that keep a branch from being deactivated. */
    public const BLOCKING_TERMINAL_STATUSES = ['pending', 'active'];

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly AuditTrail $audit,
    ) {}

    public function handle(ChangeBranchStatus $command): void
    {
        if (! is_bool($command->isActive)) {
            throw OrganizationRefused::validation('The is_active must be true or false.');
        }

        $wanted = $command->isActive;
        $connection = $this->tenant->get();

        $connection->transaction(function () use ($connection, $command, $wanted): void {
            $branch = BranchLookup::find($connection, $command->branchId, lock: true);

            if ((bool) $branch->is_active === $wanted) {
                throw OrganizationRefused::validation(
                    'The branch is already '.($wanted ? 'active' : 'inactive').'.'
                );
            }

            if (! $wanted) {
                $blocking = $connection->table('terminals')
                    ->where('branch_id', (string) $branch->id)
                    ->whereNull('deleted_at')
                    ->whereIn('status', self::BLOCKING_TERMINAL_STATUSES)
                    ->count();

                if ($blocking > 0) {
                    throw OrganizationRefused::hasActiveTerminals(
                        "Branch [{$branch->id}] still has {$blocking} pending or active terminal(s)."
                    );
                }

                LastBranch::requireAnother($connection, (string) $branch->id);
            }

            $connection->table('branches')->where('id', (string) $branch->id)->update([
                'is_active' => $wanted,
                'updated_by' => $command->actorId,
                'updated_at' => $this->clock->timestamp(),
            ]);

            $this->audit->record(
                OrganizationPermissions::MODULE,
                $command->actorId,
                $wanted ? self::ACTION_REACTIVATED : self::ACTION_DEACTIVATED,
                'branch',
                (string) $branch->id,
                ['is_active' => ! $wanted],
                ['is_active' => $wanted],
                (string) $branch->id,
            );
        });
    }
}
