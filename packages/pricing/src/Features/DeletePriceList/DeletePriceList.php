<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Features\DeletePriceList;

/**
 * Soft-delete a price list (T-029 AC-18).
 */
final class DeletePriceList
{
    public function __construct(
        public readonly string $priceListId,
        public readonly ?string $actorId = null,
    ) {}
}
