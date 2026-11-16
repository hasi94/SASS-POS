<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Engine;

use Flexipos\Contracts\Pricing\PricedPurchaseLine;
use Flexipos\Contracts\Pricing\PricingRefused;
use Flexipos\Contracts\Pricing\PurchaseLineQuote;
use Flexipos\Contracts\Pricing\TaxContext;

/**
 * One purchase line, priced, a pure function (T-034 D-53a). The fixed order,
 * each named step rounded half-up to four decimals before the next consumes it:
 *
 * 1. `subtotal = round(quantity x unitCost)`, the **line-unit** quantity;
 * 2. `discount = percentOf(subtotal, discountPercent)`, then
 *    `taxable = subtotal - discount`: **discount before tax**;
 * 3. tax on `taxable` ({@see TaxCalculator}, purchase context, the base
 *    quantity feeding any fixed tax);
 * 4. `lineTotal = tax.net + tax.total`, which is `taxable + tax` for an
 *    exclusive group and `taxable` for an inclusive one, so nobody branches on
 *    inclusivity.
 *
 * Like every pure engine class it imports nothing from Illuminate.
 */
final class PurchaseLineCalculator
{
    /**
     * @param  list<TaxMember>  $members  the members of the quote's tax group
     *
     * @throws PricingRefused
     */
    public static function compute(PurchaseLineQuote $quote, array $members, bool $taxGroupIsInclusive): PricedPurchaseLine
    {
        $subtotal = Decimal::round(Decimal::money($quote->quantity, 'quantity')->multipliedBy(Decimal::money($quote->unitCost, 'unitCost')));

        $discount = Decimal::percentOf($subtotal, Decimal::rate($quote->discountPercent, 'discountPercent'));

        $taxable = $subtotal->minus($discount);

        $tax = TaxCalculator::compute(
            $members,
            Decimal::text($taxable),
            $quote->baseQuantity,
            $quote->documentDate,
            TaxContext::Purchase,
            $taxGroupIsInclusive,
        );

        $lineTotal = Decimal::money($tax->net, 'net')->plus(Decimal::money($tax->total, 'tax'));

        return new PricedPurchaseLine(
            Decimal::text($subtotal),
            Decimal::text($discount),
            Decimal::text($taxable),
            $tax,
            Decimal::text($lineTotal),
        );
    }
}
