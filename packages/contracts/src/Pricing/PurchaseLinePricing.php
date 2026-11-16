<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Pricing;

/**
 * Prices one purchase line (T-034 D-53a): subtotal, discount, tax and total.
 *
 * A separate interface from {@see PricingEngine} on purpose: a new method there
 * would break every other implementer of it, and a purchase line has no price
 * ladder to resolve. Like the engine it is **the only place** a discount or a
 * tax is computed - a caller never multiplies by a percentage itself - and the
 * arithmetic is exact, half-up at four decimals at each named step.
 *
 * The answer is read from the tenant database the process is currently entered
 * into, and from nowhere else.
 */
interface PurchaseLinePricing
{
    /**
     * @throws PricingRefused
     */
    public function quotePurchaseLine(PurchaseLineQuote $quote): PricedPurchaseLine;
}
