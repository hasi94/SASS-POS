<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Finance;

/**
 * The status of a fiscal period (T-032 AC-38).
 *
 * The cases are declared in rank order, open below soft_closed below closed,
 * which is the order AC-40 compares.
 */
enum PeriodStatus: string
{
    case Open = 'open';
    case SoftClosed = 'soft_closed';
    case Closed = 'closed';

    /**
     * The position in the close order (AC-40): open 0, soft_closed 1, closed 2.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Open => 0,
            self::SoftClosed => 1,
            self::Closed => 2,
        };
    }
}
