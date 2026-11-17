<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Features\AddPriceListItem;

final class AddPriceListItemResult
{
    public function __construct(
        public readonly string $priceListItemId,
    ) {}
}
