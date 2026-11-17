<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Features\UpdatePriceList;

/**
 * Update a price list (T-029 AC-12).
 */
final class UpdatePriceList
{
    /**
     * @param  array<string, mixed>  $fields
     */
    public function __construct(
        public readonly string $priceListId,
        public readonly array $fields,
        public readonly ?string $actorId = null,
    ) {}
}
