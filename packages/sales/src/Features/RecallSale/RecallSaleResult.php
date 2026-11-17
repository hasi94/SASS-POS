<?php

declare(strict_types=1);

namespace Flexipos\Sales\Features\RecallSale;

final class RecallSaleResult
{
    public function __construct(
        public readonly string $holdId,
        public readonly string $status,
        public readonly string $shiftId,
    ) {}
}
