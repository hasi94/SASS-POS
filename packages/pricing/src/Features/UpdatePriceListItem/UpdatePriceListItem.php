<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Features\UpdatePriceListItem;

/**
 * Change the price or item discount of a price list item (T-029 AC-13).
 */
final class UpdatePriceListItem
{
    /**
     * @param  array<string, mixed>  $fields
     */
    public function __construct(
        public readonly string $priceListItemId,
        public readonly array $fields,
        public readonly ?string $actorId = null,
    ) {}
}
