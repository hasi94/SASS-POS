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
                $parent = $this->rows->anySharedLock((string) $row->parent_id);

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
