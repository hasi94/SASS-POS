<?php

declare(strict_types=1);

namespace Flexipos\Finance\Journal\Ports;

use Flexipos\Contracts\Finance\AccountUsage;
use Flexipos\Finance\Support\TenantConnection;

/**
 * The journal's answer to "does this account have postings?" (T-033 Ruling 11,
 * AC-59, AC-62 to AC-64, D-12).
 *
 * True when any line, in an entry of **any** status, names the account. One
 * existence read on `journal_lines`, on the `(account_id, entry_id)` index,
 * under a shared lock: T-032's account handlers hold the account row exclusively
 * before they ask, so a poster that holds the shared lock makes them wait
 * (AC-26). A malformed or unknown id simply matches no line and answers false.
 *
 * Reads journal tables only (AC-63); the accounts code never names one.
 */
final class JournalAccountUsage implements AccountUsage
{
    public function __construct(private readonly TenantConnection $tenant) {}

    public function hasPostings(string $accountId): bool
    {
        return $this->tenant->get()
            ->table('journal_lines')
            ->where('account_id', $accountId)
            ->sharedLock()
            ->exists();
    }
}
