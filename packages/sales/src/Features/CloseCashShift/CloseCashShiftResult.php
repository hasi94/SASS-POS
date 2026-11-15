<?php

declare(strict_types=1);

namespace Flexipos\Sales\Features\CloseCashShift;

final class CloseCashShiftResult
{
    /**
     * @param  array<string, string>  $totalsByMethod  payment-method code to the summed, signed tender amount
     */
    public function __construct(
        public readonly string $shiftId,
        public readonly string $status,
        public readonly string $expectedCash,
        public readonly string $countedCash,
        public readonly string $variance,
        public readonly string $gross,
        public readonly string $discounts,
        public readonly string $tax,
        public readonly string $net,
        public readonly int $salesCount,
        public readonly int $refundsCount,
        public readonly array $totalsByMethod,
        public readonly bool $replayed,
    ) {}
}
