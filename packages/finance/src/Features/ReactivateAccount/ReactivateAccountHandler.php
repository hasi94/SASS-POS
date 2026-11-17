<?php

declare(strict_types=1);

namespace Flexipos\Finance\Features\ReactivateAccount;

use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Finance\Accounts\AccountRefusals;
use Flexipos\Finance\Accounts\AccountRows;
use Flexipos\Finance\Exceptions\FinanceRefusal;
use Flexipos\Finance\Exceptions\FinanceRefused;
use Flexipos\Finance\Registry\FinanceAuditActions;
use Flexipos\Finance\Registry\FinancePermissions;
use Flexipos\Finance\Support\Authorize;
use Flexipos\Finance\Support\Input;
use Flexipos\Finance\Support\TenantConnection;
use Flexipos\Kernel\Schema\Support\RunnerClock;

/**
 * Reactivate an account (T-032 plan Ruling 6, step 11, AC-24).
 *
 * The parent is read under a shared lock and must be live and active: a
 * child of an inactive or deleted header would otherwise come back to life
 * under a parent that cannot take it (the AC-10 versus AC-24 gap, F-11).
 * Only the two facts a reactivation depends on are checked; the parent being
 * a header of the same type was settled when the child was placed.
 *
 * **Lock order: the parent (shared), then the child (exclusive)**, the same
 * order as a deactivation of the parent (which locks the parent, then counts
 * its children), designed so the common path does not cycle (D-33). The
 * parent id is peeked without a lock to know which row to lock first, and
 * compared with the child's own `parent_id` once that is locked; a re-parent
 * in between (rare: it locks the whole chart) costs a late lock on the new
 * parent, not a wrong decision. The late re-lock path, like a retype in
 * UpdateAccountHandler, takes the child before the parent (NOT VERIFIED under
 * ENGINE_HOLD, recovered by the transaction retry).
 */
final class ReactivateAccountHandler
{
    public const ACTION = 'account_reactivated';

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly Authorize $authorize,
        private readonly AccountRows $rows,
        private readonly RunnerClock $clock,
        private readonly AuditTrail $audit,
    ) {}

    /**
     * @throws FinanceRefused
     */
    public function handle(ReactivateAccount $command): void
    {
        $this->authorize->require($command->actorId, FinancePermissions::ACCOUNTS_MANAGE);

        $id = Input::id($command->accountId, 'account_id');

        $this->tenant->get()->transaction(function () use ($command, $id): void {
            $peekedParentId = $this->rows->parentIdUnlocked($id);
            $parent = $peekedParentId === null ? null : $this->rows->anySharedLock($peekedParentId);

            $row = $this->rows->liveForUpdate($id);

            if ($row === null) {
                throw AccountRefusals::notLive($id);
            }

            if ((bool) $row->is_active) {
                throw FinanceRefused::because(
                    FinanceRefusal::AlreadyActive,
                    'The account is already active.',
                    'account_id',
                );
            }

            if ($row->parent_id !== null) {
                if ((string) $row->parent_id !== $peekedParentId) {
                    $parent = $this->rows->anySharedLock((string) $row->parent_id);
                }

                if ($parent === null || $parent->deleted_at !== null) {
                    throw FinanceRefused::because(
                        FinanceRefusal::ParentNotLive,
                        'The parent account has been deleted.',
                        'parent_id',
                    );
                }

                if (! (bool) $parent->is_active) {
                    throw FinanceRefused::because(
                        FinanceRefusal::ParentInactive,
                        'The parent account is inactive.',
                        'parent_id',
                    );
                }
            }

            $this->rows->update($id, [
                'is_active' => true,
                'updated_at' => $this->clock->timestamp(),
                'updated_by' => $command->actorId,
            ]);

            $after = $this->rows->anyForUpdate($id);

            $this->audit->record(
                FinanceAuditActions::MODULE,
                $command->actorId,
                self::ACTION,
                'account',
                $id,
                (array) $row,
                $after === null ? null : (array) $after,
            );
        }, 2);
    }
}
