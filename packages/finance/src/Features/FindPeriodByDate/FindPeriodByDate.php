<?php

declare(strict_types=1);

namespace Flexipos\Finance\Features\FindPeriodByDate;

/**
 * Find the fiscal period covering a date (T-032 AC-62, AC-64).
 */
final class FindPeriodByDate
{
    /**
     * @param  mixed  $date  a strict `Y-m-d` string
     */
    public function __construct(
        public readonly mixed $date,
        public readonly ?string $actorId = null,
    ) {}
}
