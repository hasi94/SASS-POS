<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Finance;

/**
 * The closed refusal vocabulary of {@see PeriodGate} (T-032 AC-47, AC-48).
 */
enum PeriodRefusal: string
{
    /** The date is null, empty, not a real strict `Y-m-d`, or a date-time (AC-48). */
    case InvalidDate = 'invalid_date';

    /** No period covers the date, or the tenant has no fiscal year (AC-47, AC-48). */
    case NoFiscalPeriod = 'no_fiscal_period';

    /** The period is closed; refused for every actor. */
    case PeriodClosed = 'period_closed';

    /** The period is soft-closed and the actor lacks `finance.periods.post_adjustment`. */
    case PeriodSoftClosed = 'period_soft_closed';
}
