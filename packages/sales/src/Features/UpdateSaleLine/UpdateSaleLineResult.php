<?php

declare(strict_types=1);

namespace Flexipos\Sales\Features\UpdateSaleLine;

final class UpdateSaleLineResult
{
    public function __construct(
        public readonly string $lineId,
        public readonly string $lineTotal,
        public readonly string $saleTotal,
    ) {}
}
