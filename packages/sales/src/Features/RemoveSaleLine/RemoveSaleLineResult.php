<?php

declare(strict_types=1);

namespace Flexipos\Sales\Features\RemoveSaleLine;

final class RemoveSaleLineResult
{
    public function __construct(
        public readonly string $lineId,
        public readonly string $saleTotal,
    ) {}
}
