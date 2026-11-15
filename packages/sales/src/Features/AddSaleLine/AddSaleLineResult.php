<?php

declare(strict_types=1);

namespace Flexipos\Sales\Features\AddSaleLine;

final class AddSaleLineResult
{
    public function __construct(
        public readonly string $lineId,
        public readonly int $lineNo,
        public readonly string $lineTotal,
        public readonly string $saleTotal,
    ) {}
}
