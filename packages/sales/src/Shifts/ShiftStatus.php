<?php

declare(strict_types=1);

namespace Flexipos\Sales\Shifts;

/**
 * `cash_shifts.status` (`docs/database.md` section 6.7). `closing` is a value
 * the column allows and nothing this task ships ever writes.
 */
enum ShiftStatus: string
{
    case Open = 'open';
    case Closing = 'closing';
    case Closed = 'closed';
    case Reconciled = 'reconciled';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
