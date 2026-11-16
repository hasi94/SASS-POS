<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Finance;

/**
 * Does a fiscal year hold journal entries? (T-032 AC-37, D-7.)
 *
 * The default answers false; FP-261 rebinds it. The dates are passed so the
 * implementation need not read Finance's tables to find the year's span.
 */
interface FiscalYearUsage
{
    /**
     * @param  string  $startDate  `Y-m-d`, inclusive
     * @param  string  $endDate  `Y-m-d`, inclusive
     */
    public function hasEntries(string $fiscalYearId, string $startDate, string $endDate): bool;
}
