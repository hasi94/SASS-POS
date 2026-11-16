<?php

declare(strict_types=1);

namespace Flexipos\Finance\Features\TransitionFiscalPeriod;

/**
 * Move a fiscal period one step along open, soft_closed, closed (T-032
 * AC-38 to AC-44).
 */
final class TransitionFiscalPeriod
{
    /**
     * @param  array<array-key, mixed>  $fields  `status` (the target), `expected_status` and `reason`, nothing else
     */
    public function __construct(
        public readonly string $periodId,
        public readonly array $fields,
        public readonly ?string $actorId = null,
    ) {}
}
