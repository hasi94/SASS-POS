<?php

declare(strict_types=1);

namespace Flexipos\Finance\Periods;

use Flexipos\Contracts\Finance\PeriodStatus;

/**
 * A neighbouring period as {@see PeriodTransitions::checkOrder()} sees it:
 * enough to decide the order rule and to name the period in the refusal.
 *
 * Handlers build it from a **locked** row (Ruling 6).
 */
final readonly class PeriodRef
{
    public function __construct(
        public string $id,
        public string $name,
        public PeriodStatus $status,
    ) {}
}
