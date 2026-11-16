<?php

declare(strict_types=1);

namespace Flexipos\Finance\Accounts;

use Flexipos\Contracts\Finance\AccountUsage;

/**
 * The default {@see AccountUsage}: no account has postings, because nothing
 * posts yet (T-032 D-7, AC-25).
 *
 * **This is a forward dependency and not a guarantee.** Until FP-261 binds
 * the real implementation, every rule that asks "does this account have
 * postings?" (AC-14, AC-15, AC-19, AC-22, AC-23) refuses nothing in
 * production. A test proves each rule by binding a double that says yes.
 */
final class NoAccountUsage implements AccountUsage
{
    public function hasPostings(string $accountId): bool
    {
        return false;
    }
}
