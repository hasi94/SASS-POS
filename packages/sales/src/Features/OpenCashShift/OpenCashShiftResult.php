<?php

declare(strict_types=1);

namespace Flexipos\Sales\Features\OpenCashShift;

final class OpenCashShiftResult
{
    public function __construct(
        public readonly string $shiftId,
        public readonly string $number,
    ) {}
}
