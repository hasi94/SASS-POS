<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Features\UnassignPriceList;

/**
 * Remove a price list assignment (T-029 AC-14, AC-18).
 */
final class UnassignPriceList
{
    public function __construct(
        public readonly string $priceListAssignmentId,
        public readonly ?string $actorId = null,
    ) {}
}
