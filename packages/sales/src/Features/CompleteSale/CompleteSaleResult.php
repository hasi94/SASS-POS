<?php

declare(strict_types=1);

namespace Flexipos\Sales\Features\CompleteSale;

final class CompleteSaleResult
{
    public function __construct(
        public readonly string $saleId,
        public readonly string $number,
        public readonly string $total,
        public readonly string $paidTotal,
        public readonly string $changeDue,
    ) {}
}
