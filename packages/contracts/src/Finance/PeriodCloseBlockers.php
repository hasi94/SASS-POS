<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Finance;

/**
 * What stops a period from being soft-closed or closed, for example draft
 * journal entries dated in it (T-032 AC-41, D-7).
 *
 * The default reports none; FP-261 rebinds it. A reopen is never blocked.
 */
interface PeriodCloseBlockers
{
    /**
     * @param  string  $startDate  `Y-m-d`, inclusive
     * @param  string  $endDate  `Y-m-d`, inclusive
     * @return list<PeriodBlocker>
     */
    public function blockersFor(string $periodId, string $startDate, string $endDate): array;
}
