<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Features\CreatePriceList;

final class CreatePriceListResult
{
    public function __construct(
        public readonly string $priceListId,
    ) {}
}
