<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Pricing;

/**
 * How the winning price was arrived at (AC-15, AC-16, D-13).
 */
enum PriceBasis: string
{
    /** A `price_list_items` row for the line's own unit, used as typed (AC-16, unit-outer). */
    case UnitMatched = 'unit_matched';

    /** A `unit_id IS NULL` row, derived from the stock-unit price by the line's unit factor. */
    case Derived = 'derived';

    /** `product_branches.selling_price` or `products.selling_price`, derived by the unit factor (D-13). */
    case Product = 'product';
}
