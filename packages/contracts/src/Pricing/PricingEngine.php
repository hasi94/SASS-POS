<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Pricing;

/**
 * The one engine that prices a line (T-029 plan Ruling 2, AC-22, AC-25).
 *
 * **The only place tax, price-list and discount arithmetic happens.** A caller
 * never reads `tax_rates` or `price_list_items` itself and never multiplies by
 * a rate or a percentage: it asks here. The arithmetic is exact (`BigDecimal`,
 * never a float), rounded half-up at four decimals at each named step, and
 * every refusal is a {@see PricingRefused} naming one {@see PricingRefusal}.
 *
 * The answer is read from the tenant database the process is currently entered
 * into, and from nowhere else.
 */
interface PricingEngine
{
    /**
     * Price one document line: price-ladder, subtotal, discount, tax, total.
     *
     * @throws PricingRefused
     */
    public function quoteLine(LineQuote $quote): PricedLine;

    /**
     * The tax of an amount the caller already holds (a purchase line, say).
     *
     * @throws PricingRefused
     */
    public function computeTax(TaxQuote $quote): TaxBreakdown;

    /**
     * A bill-scope discount against the summed pre-tax discounted subtotals.
     *
     * @throws PricingRefused
     */
    public function billDiscount(BillQuote $quote): BillDiscount;
}
