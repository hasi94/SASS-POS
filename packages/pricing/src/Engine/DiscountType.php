<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Engine;

/**
 * `discounts.type` (AC-19).
 */
enum DiscountType: string
{
    /** `value` is a percentage, 0 to 100. */
    case Percentage = 'percentage';

    /** `value` is a money amount, never more than the base it reduces. */
    case Fixed = 'fixed';

    /** Stored, never computed: `unsupported_discount_type` (AC-20.7). */
    case BuyXGetY = 'buy_x_get_y';
}
