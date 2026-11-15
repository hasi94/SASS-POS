<?php

declare(strict_types=1);

namespace Flexipos\Sales\Features\HoldSale;

final class HoldSaleResult
{
    public function __construct(
        public readonly string $holdId,
        public readonly string $fromStatus,
    ) {}
}
