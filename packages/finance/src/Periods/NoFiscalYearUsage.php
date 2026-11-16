<?php

declare(strict_types=1);

namespace Flexipos\Finance\Periods;

use Flexipos\Contracts\Finance\FiscalYearUsage;

/**
 * The default {@see FiscalYearUsage}: no year has journal entries, because
 * none exist yet (T-032 D-7, AC-25, AC-37). Replaced by FP-261.
 */
final class NoFiscalYearUsage implements FiscalYearUsage
{
    public function hasEntries(string $fiscalYearId, string $startDate, string $endDate): bool
    {
        return false;
    }
}
