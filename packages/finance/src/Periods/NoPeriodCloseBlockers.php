<?php

declare(strict_types=1);

namespace Flexipos\Finance\Periods;

use Flexipos\Contracts\Finance\PeriodCloseBlockers;

/**
 * The default {@see PeriodCloseBlockers}: nothing blocks a close, because no
 * draft entry exists yet (T-032 D-7, AC-25, AC-41). Replaced by FP-261.
 */
final class NoPeriodCloseBlockers implements PeriodCloseBlockers
{
    public function blockersFor(string $periodId, string $startDate, string $endDate): array
    {
        return [];
    }
}
