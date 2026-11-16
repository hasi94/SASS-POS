<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Finance;

/**
 * Does an account have postings? (T-032 AC-25, D-7.)
 *
 * Finance may not read another module's tables, so it asks. The default
 * implementation answers false; FP-261 rebinds it to the journal. Until then
 * the guards that depend on this answer refuse nothing in production.
 */
interface AccountUsage
{
    public function hasPostings(string $accountId): bool;
}
