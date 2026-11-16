<?php

declare(strict_types=1);

namespace Flexipos\Finance\Features\GetAccount;

use Flexipos\Finance\Accounts\AccountRows;
use Flexipos\Finance\Exceptions\FinanceRefusal;
use Flexipos\Finance\Exceptions\FinanceRefused;
use Flexipos\Finance\Registry\FinancePermissions;
use Flexipos\Finance\Support\Authorize;
use Flexipos\Finance\Support\Input;

/**
 * Read one account (T-032 AC-61, AC-64). A soft-deleted account is not
 * returned: it is `account_not_found`, the same answer as an id that never
 * existed, so a read does not reveal a deleted row beyond its reserved code.
 */
final class GetAccountHandler
{
    public function __construct(
        private readonly Authorize $authorize,
        private readonly AccountRows $rows,
    ) {}

    /**
     * @return array<string, mixed>
     *
     * @throws FinanceRefused
     */
    public function handle(GetAccount $query): array
    {
        $this->authorize->require($query->actorId, FinancePermissions::ACCOUNTS_VIEW);

        $id = Input::id($query->accountId, 'account_id');
        $row = $this->rows->liveByIdUnlocked($id);

        if ($row === null) {
            throw FinanceRefused::because(
                FinanceRefusal::AccountNotFound,
                "The account [{$id}] does not exist.",
                'account_id',
                ['account' => $id],
            );
        }

        return (array) $row;
    }
}
