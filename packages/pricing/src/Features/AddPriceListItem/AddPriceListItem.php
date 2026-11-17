<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Features\AddPriceListItem;

/**
 * Add a price to a price list (T-029 AC-13).
 */
final class AddPriceListItem
{
    /**
     * @param  array<string, mixed>  $fields
     */
    public function __construct(
        public readonly array $fields,
        public readonly ?string $actorId = null,
    ) {}
}
