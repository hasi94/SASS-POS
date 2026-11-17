<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Organization;

/**
 * The closed refusal vocabulary of `DocumentNumbers` (T-030 AC-40, AC-50).
 *
 * Every case is reachable from the published contract alone; nothing here names
 * a table or a column.
 */
enum DocumentNumbersRefusal: string
{
    /**
     * The sequence's `reset_period` is anything but `never`. Nothing can create
     * such a row today (there is no numbering CRUD), and a periodic reset is not
     * built, so a row that asks for one is refused rather than numbered wrongly.
     */
    case UnsupportedResetPeriod = 'unsupported_reset_period';

    /**
     * Prefix, padded digits and suffix together would exceed 30 characters, the
     * width of `sales.number` and `cash_shifts.number` (R-27).
     */
    case NumberTooLong = 'number_too_long';
}
