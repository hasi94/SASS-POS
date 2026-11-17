<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Finance;

use Flexipos\Catalog\Support\TenantConnection;
use Flexipos\Contracts\Finance\AccountReferrer;
use Flexipos\Contracts\Finance\AccountReferrers;

/**
 * Who in the catalog names an account (T-032 AC-14, AC-15, AC-19, AC-23,
 * AC-24, AC-57).
 *
 * Finance asks this through the published {@see AccountReferrers} port before
 * it retypes, recodes, deactivates or deletes an account, so it never names
 * catalog's tables (AC-51's spirit).
 *
 * **Every count is a locking read** (`sharedLock()`), live and soft-deleted
 * separately. A soft delete does not trip a foreign key, so a plain count could
 * miss a referrer that a concurrent product or category write is adding; the
 * shared lock makes that write wait for (or be waited for by) the account's
 * own transaction. It must be called inside that transaction.
 *
 * The columns are listed here and nowhere else, so a sixth account column added
 * to either table is one edit in one place (and the T-032 column counter fails
 * until it is).
 */
final class CatalogAccountReferrers implements AccountReferrers
{
    /**
     * Table and column, in the order the kinds are reported.
     */
    private const COLUMNS = [
        ['products', 'income_account_id'],
        ['products', 'cogs_account_id'],
        ['products', 'inventory_account_id'],
        ['categories', 'income_account_id'],
    ];

    public function __construct(private readonly TenantConnection $tenant) {}

    public function referrersOf(string $accountId): array
    {
        $connection = $this->tenant->get();
        $found = [];

        foreach (self::COLUMNS as [$table, $column]) {
            $live = $connection->table($table)->where($column, $accountId)->whereNull('deleted_at')->sharedLock()->count();
            $deleted = $connection->table($table)->where($column, $accountId)->whereNotNull('deleted_at')->sharedLock()->count();

            $found[] = new AccountReferrer($table.'.'.$column, $live, $deleted);
        }

        return $found;
    }
}
