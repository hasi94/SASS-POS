<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Units;

/**
 * One candidate `unit_conversions` row, as the ladder reads it: `from_unit_id
 * = the line unit, to_unit_id = the product's own stock unit` (T-027 plan
 * Ruling 3, F-7). Never inverted, never chained - `UnitLadder` is handed at
 * most one of these per ladder step (product-scoped, then global), already
 * looked up by the exact key.
 */
final readonly class ConversionRow
{
    public function __construct(
        public string $id,
        public ?string $productId,
        public string $fromUnitId,
        public string $toUnitId,
        public string $factor,
    ) {}
}
