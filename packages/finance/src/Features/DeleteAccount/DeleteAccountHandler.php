<?php

declare(strict_types=1);

namespace Flexipos\Finance\Features\DeleteAccount;

use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Contracts\Finance\AccountUsage;
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
 * Soft-delete an account (T-032 plan Ruling 6, step 11, AC-23).
 *
 * Refused for a system account, an account with live children, an account
 * with postings, and an account **any** record names, live or soft-deleted:
 * a soft delete does not trip a foreign key, so the check is explicit, and a
 * dead referrer would otherwise fail later at posting time instead of now
 * (D-14). Nothing is cascaded.
 *
 * On success `deleted_at` is set and the row stays: its code stays reserved
 * (AC-3) and it disappears from every default list. A second delete finds no
 * live row and is refused `account_not_live` with no second audit row.
 */
final class DeleteAccountHandler
{
    public const ACTION = 'account_deleted';

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly Authorize $authorize,
        private readonly AccountRows $rows,
        private readonly AccountUsage $usage,
        private readonly ReferrerCensus $referrers,
        private readonly RunnerClock $clock,
        private readonly AuditTrail $audit,
    ) {}

    /**
     * @throws FinanceRefused
     */
    public function handle(DeleteAccount $command): void
    {
        $this->authorize->require($command->actorId, FinancePermissions::ACCOUNTS_MANAGE);

        $id = Input::id($command->accountId, 'account_id');

        $this->tenant->get()->transaction(function () use ($command, $id): void {
            $row = $this->rows->liveForUpdate($id);

            if ($row === null) {
                throw AccountRefusals::notLive($id);
            }

            AccountRules::systemRemoval((bool) $row->is_system);

            if ($this->rows->liveChildCountSharedLock($id) > 0) {
                throw FinanceRefused::because(
                    FinanceRefusal::HasChildren,
                    'The account has live child accounts.',
                    'account_id',
                );
            }

            if ($this->usage->hasPostings($id)) {
                throw FinanceRefused::because(
                    FinanceRefusal::HasPostings,
                    'The account has postings.',
                    'account_id',
                );
            }

            $named = $this->referrers->kinds($id);

            if ($named !== []) {
                throw AccountRefusals::inUse($named);
            }

            $now = $this->clock->timestamp();

            $this->rows->update($id, ['deleted_at' => $now, 'updated_at' => $now, 'updated_by' => $command->actorId]);

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
