<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Finance;

/**
 * The status of a fiscal year (T-032 AC-45).
 *
 * `Closing` exists because the column's design lists it. No code path enters
 * it: the year-end retained-earnings entry has no owner yet (D-5), and a state
 * with no worker would strand a year.
 */
enum FiscalYearStatus: string
{
    case Open = 'open';
    case Closing = 'closing';
    case Closed = 'closed';
}
