<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Pricing;

/**
 * One fully priced document line (AC-22, plan Ruling 2), shaped to populate
 * `sale_items` without a later reshape (`list_price`, `unit_price`,
 * `discount_id`, `discount_amount`, `tax_amount`, `tax_breakdown`,
 * `line_total`). Every amount is a canonical four-decimal string.
 *
 * The fixed order that produced it: `unitPrice`, `subtotal = unitPrice x the
 * line-unit quantity` (never the base quantity, D-12), the discount, tax on
 * `discountedSubtotal`, then `lineTotal`.
 */
final readonly class PricedLine
{
    public string $listPrice;

    public ?string $listDiscountPercent;

    public string $unitPrice;

    public string $subtotal;

    public string $discountAmount;

    public ?string $discountId;

    public string $discountedSubtotal;

    public string $taxGroupId;

    public string $lineTotal;

    public function __construct(
        string $listPrice,
        ?string $listDiscountPercent,
        string $unitPrice,
        public PriceSource $source,
        string $subtotal,
        string $discountAmount,
        ?string $discountId,
        string $discountedSubtotal,
        string $taxGroupId,
        public TaxBreakdown $tax,
        string $lineTotal,
    ) {
        $this->listPrice = Shape::money($listPrice, 'listPrice');
        $this->listDiscountPercent = $listDiscountPercent === null ? null : Shape::rate($listDiscountPercent, 'listDiscountPercent');
        $this->unitPrice = Shape::money($unitPrice, 'unitPrice');
        $this->subtotal = Shape::money($subtotal, 'subtotal');
        $this->discountAmount = Shape::money($discountAmount, 'discountAmount');
        $this->discountId = $discountId === null ? null : Shape::ulid($discountId, 'discountId');
        $this->discountedSubtotal = Shape::money($discountedSubtotal, 'discountedSubtotal');
        $this->taxGroupId = Shape::ulid($taxGroupId, 'taxGroupId');
        $this->lineTotal = Shape::money($lineTotal, 'lineTotal');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'list_price' => $this->listPrice,
            'list_discount_percent' => $this->listDiscountPercent,
            'unit_price' => $this->unitPrice,
            'source' => $this->source->toArray(),
            'subtotal' => $this->subtotal,
            'discount_amount' => $this->discountAmount,
            'discount_id' => $this->discountId,
            'discounted_subtotal' => $this->discountedSubtotal,
            'tax_group_id' => $this->taxGroupId,
            'tax' => $this->tax->toArray(),
            'line_total' => $this->lineTotal,
        ];
    }
}
