<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Features\RemovePriceListItem;

/**
 * Remove a price list item (T-029 AC-13).
 */
final class RemovePriceListItem
{
    public function __construct(
        public readonly string $priceListItemId,
        public readonly ?string $actorId = null,
    ) {}
}
