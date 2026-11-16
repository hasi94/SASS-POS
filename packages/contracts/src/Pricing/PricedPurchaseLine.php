<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Pricing;

/**
 * One fully priced purchase line (T-034 D-53a). Every amount is a canonical
 * four-decimal string.
 *
 * The fixed order that produced it: `subtotal = quantity x unitCost`, the
 * discount, `taxableAmount = subtotal - discountAmount` (**discount before
 * tax**), tax on `taxableAmount`, then `lineTotal = tax.net + tax.total`: that
 * equals `taxableAmount + tax` for an exclusive group and `taxableAmount` for an
 * inclusive one, whose tax is already inside it, so no caller branches on
 * inclusivity.
 */
final readonly class PricedPurchaseLine
{
    public string $subtotal;

    public string $discountAmount;

    public string $taxableAmount;

    public string $lineTotal;

    public function __construct(
        string $subtotal,
        string $discountAmount,
        string $taxableAmount,
        public TaxBreakdown $tax,
        string $lineTotal,
    ) {
        $this->subtotal = Shape::money($subtotal, 'subtotal');
        $this->discountAmount = Shape::money($discountAmount, 'discountAmount');
        $this->taxableAmount = Shape::money($taxableAmount, 'taxableAmount');
        $this->lineTotal = Shape::money($lineTotal, 'lineTotal');
    }
}
