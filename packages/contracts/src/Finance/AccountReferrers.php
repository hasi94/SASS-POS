<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Finance;

/**
 * Who points at an account? (T-032 AC-14, AC-15, AC-19, AC-23, AC-24.)
 *
 * Implemented by each package that stores an account id (catalog, customers)
 * and discovered through {@see DeclaresAccountReferrers}.
 *
 * **Implementations must count with a locking read** (`sharedLock()`) inside
 * the caller's transaction, live and soft-deleted separately. A soft delete
 * does not trip a foreign key, so an unlocked count could miss a referrer
 * that a concurrent write is adding (AC-57).
 */
interface AccountReferrers
{
    /**
     * Every kind of record this implementation owns that names the account,
     * with live and soft-deleted counts. A kind with no referrer may be
     * omitted or reported as zero.
     *
     * @return list<AccountReferrer>
     */
    public function referrersOf(string $accountId): array;
}
