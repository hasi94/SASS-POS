<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Documents;

use Flexipos\Contracts\Catalog\UnitSnapshot;
use Flexipos\Contracts\Pricing\PricedPurchaseLine;

/**
 * One order line, fully quoted: the unit quad the resolver froze, the cost and
 * discount the caller gave, the tax group used, and every amount the pricing
 * contract returned (T-034 AC-42, AC-43).
 *
 * It holds values only. The line is stored from {@see self::columns()} and
 * **never recomputed on read** (AC-48): a later change to a rate, a group, a
 * price or the unit ladder does not touch a stored line.
 */
final readonly class QuotedLine
{
    public function __construct(
        public UnitSnapshot $unit,
        public string $unitCost,
        public string $discountPercent,
        public string $taxGroupId,
        public PricedPurchaseLine $priced,
    ) {}

    /**
     * The `purchase_order_items` columns this quote decides (no `id`, `order_id`,
     * `line_no`, product, description or dates: those are the slice's).
     *
     * @return array<string, string>
     */
    public function columns(): array
    {
        return [
            'unit_id' => $this->unit->unitId,
            'quantity' => $this->unit->quantity,
            'unit_factor' => $this->unit->unitFactor,
            'base_quantity' => $this->unit->baseQuantity,
            'unit_cost' => $this->unitCost,
            'subtotal' => $this->priced->subtotal,
            'discount_percent' => $this->discountPercent,
            'discount_amount' => $this->priced->discountAmount,
            'tax_group_id' => $this->taxGroupId,
            'tax_amount' => $this->priced->tax->total,
            'line_total' => $this->priced->lineTotal,
        ];
    }
}
