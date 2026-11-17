<?php

declare(strict_types=1);

namespace Flexipos\Sales\Features\CreateSale;

final class CreateSaleResult
{
    public function __construct(
        public readonly string $saleId,
        public readonly string $shiftId,
        public readonly string $businessDate,
    ) {}
}
