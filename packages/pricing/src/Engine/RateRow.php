<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Engine;

use Flexipos\Contracts\Pricing\Shape;
use InvalidArgumentException;

/**
 * One `tax_rates` row as the pure engine sees it (AC-6, AC-7).
 *
 * `rate` is a percentage (`15.0000` means 15 %), or, for a `fixed` tax, an
 * amount per stock unit. The range is `[effectiveFrom, effectiveTo)`: the row
 * stops applying **on** `effectiveTo`, which is what lets the next row start on
 * the same date with no gap and no overlap.
 */
final readonly class RateRow
{
    public string $rate;

    public string $effectiveFrom;

    public ?string $effectiveTo;

    public function __construct(string $rate, string $effectiveFrom, ?string $effectiveTo = null)
    {
        $this->rate = Shape::rate($rate, 'rate');
        $this->effectiveFrom = Shape::date($effectiveFrom, 'effectiveFrom');
        $this->effectiveTo = $effectiveTo === null ? null : Shape::date($effectiveTo, 'effectiveTo');

        if ($this->effectiveTo !== null && $this->effectiveTo < $this->effectiveFrom) {
            throw new InvalidArgumentException('[effectiveTo] must not be before [effectiveFrom].');
        }
    }
}
