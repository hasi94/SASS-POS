<?php

declare(strict_types=1);

namespace Flexipos\Sales\Features\ReconcileCashShift;

final class ReconcileCashShiftResult
{
    public function __construct(
        public readonly string $shiftId,
        public readonly string $status,
        public readonly bool $replayed,
    ) {}
}
