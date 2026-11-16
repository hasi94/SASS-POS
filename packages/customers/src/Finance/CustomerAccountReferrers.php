<?php

declare(strict_types=1);

namespace Flexipos\Customers\Finance;

use Flexipos\Contracts\Finance\AccountReferrer;
use Flexipos\Contracts\Finance\AccountReferrers;
use Flexipos\Customers\Support\TenantConnection;

/**
 * Who among the customers names an account (T-032 AC-14, AC-15, AC-19, AC-23,
 * AC-24, AC-57).
 *
 * Finance asks this through the published {@see AccountReferrers} port before
 * it retypes, recodes, deactivates or deletes an account, so it never names
 * the customers table.
 *
 * **Every count is a locking read** (`sharedLock()`), live and soft-deleted
 * separately (a customer merged away is soft-deleted, so it counts as
 * deleted). A soft delete does not trip a foreign key, so a plain count could
 * miss a referrer that a concurrent customer write is adding. It must be
 * called inside the account's own transaction.
 */
final class CustomerAccountReferrers implements AccountReferrers
{
    public function __construct(private readonly TenantConnection $tenant) {}

    public function referrersOf(string $accountId): array
    {
        $connection = $this->tenant->get();

        $live = $connection->table('customers')->where('receivable_account_id', $accountId)->whereNull('deleted_at')->sharedLock()->count();
        $deleted = $connection->table('customers')->where('receivable_account_id', $accountId)->whereNotNull('deleted_at')->sharedLock()->count();

        return [new AccountReferrer('customers.receivable_account_id', $live, $deleted)];
    }
}
