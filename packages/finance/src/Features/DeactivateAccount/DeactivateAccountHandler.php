<?php

declare(strict_types=1);

namespace Flexipos\Finance\Features\DeactivateAccount;

use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Finance\Accounts\AccountRefusals;
use Flexipos\Finance\Accounts\AccountRows;
use Flexipos\Finance\Accounts\AccountRules;
use Flexipos\Finance\Accounts\ReferrerCensus;
use Flexipos\Finance\Exceptions\FinanceRefusal;
use Flexipos\Finance\Exceptions\FinanceRefused;
use Flexipos\Finance\Registry\FinanceAuditActions;
use Flexipos\Finance\Registry\FinancePermissions;
use Flexipos\Finance\Support\Authorize;
use Flexipos\Finance\Support\Input;
use Flexipos\Finance\Support\TenantConnection;
use Flexipos\Kernel\Schema\Support\RunnerClock;

/**
 * Deactivate an account (T-032 plan Ruling 6, step 11, AC-24).
 *
 * Refused for a system account, a second deactivation, an account with a
 * live **and active** child, and an account a **live** record names. A
 * deleted-only referrer does not block it: the soft-deleted record is gone
 * from every list, and deletion (not deactivation) is what protects the
 * dead reference.
 */
final class DeactivateAccountHandler
{
    public const ACTION = 'account_deactivated';

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly Authorize $authorize,
        private readonly AccountRows $rows,
        private readonly ReferrerCensus $referrers,
        private readonly RunnerClock $clock,
        private readonly AuditTrail $audit,
    ) {}

    /**
     * @throws FinanceRefused
     */
    public function handle(DeactivateAccount $command): void
    {
        $this->authorize->require($command->actorId, FinancePermissions::ACCOUNTS_MANAGE);

        $id = Input::id($command->accountId, 'account_id');

        $this->tenant->get()->transaction(function () use ($command, $id): void {
            $row = $this->rows->liveForUpdate($id);

            if ($row === null) {
                throw AccountRefusals::notLive($id);
            }

            AccountRules::systemRemoval((bool) $row->is_system);

            if (! (bool) $row->is_active) {
                throw FinanceRefused::because(
                    FinanceRefusal::AlreadyInactive,
                    'The account is already inactive.',
                    'account_id',
                );
            }

            if ($this->rows->activeChildCountSharedLock($id) > 0) {
                throw FinanceRefused::because(
                    FinanceRefusal::HasChildren,
                    'The account has an active child account.',
                    'account_id',
                );
            }

            $live = $this->referrers->kinds($id, liveOnly: true);

            if ($live !== []) {
                throw AccountRefusals::inUse($live);
            }

            $this->rows->update($id, [
                'is_active' => false,
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
