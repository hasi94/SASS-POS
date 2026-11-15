<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Engine;

use Brick\Math\BigDecimal;
use Flexipos\Contracts\Pricing\PricedLine;
use Flexipos\Contracts\Pricing\PricingRefused;
use Flexipos\Contracts\Pricing\TaxContext;

/**
 * One document line, priced, a pure function (AC-22, plan Ruling 6, D-4,
 * D-12). The fixed order, each named step rounded half-up to four decimals
 * before the next consumes it:
 *
 * 1. `unitPrice` from {@see PriceLadder};
 * 2. `subtotal = round(unitPrice x quantity)`, where `quantity` is the
 *    **line-unit** quantity, never the base quantity (D-12);
 * 3. the discount ({@see DiscountCalculator}), then
 *    `discountedSubtotal = subtotal - discountAmount`: **discount before tax**;
 * 4. tax on `discountedSubtotal` ({@see TaxCalculator}, sale context, the base
 *    quantity feeding any fixed tax);
 * 5. `lineTotal = discountedSubtotal + tax.total` for an exclusive group, or
 *    `discountedSubtotal` for an inclusive one, whose tax is already inside it.
 */
final class LineCalculator
{
    /**
     * @throws PricingRefused
     */
    public static function compute(LineInput $input): PricedLine
    {
        $price = PriceLadder::resolve($input->ladder);

        $subtotal = Decimal::round(Decimal::money($price->unitPrice, 'unitPrice')->multipliedBy(Decimal::money($input->quantity, 'quantity')));

        $discountAmount = BigDecimal::zero()->toScale(4);

        if ($input->discountId !== null) {
            $discountAmount = DiscountCalculator::forLine(
                self::findDiscount($input),
                $input->ladder->at,
                $input->product,
                $subtotal,
            );
        }

        $discounted = $subtotal->minus($discountAmount);

        $tax = TaxCalculator::compute(
            $input->taxMembers,
            Decimal::text($discounted),
            $input->ladder->baseQuantity,
            $input->documentDate,
            TaxContext::Sale,
            $input->taxGroupIsInclusive,
        );

        $lineTotal = $input->taxGroupIsInclusive ? $discounted : $discounted->plus(Decimal::money($tax->total, 'tax'));

        return new PricedLine(
            $price->listPrice,
            $price->discountPercent,
            $price->unitPrice,
            $price->source,
            Decimal::text($subtotal),
            Decimal::text($discountAmount),
            $input->discountId,
            Decimal::text($discounted),
            $input->taxGroupId,
            $tax,
            Decimal::text($lineTotal),
        );
    }

    private static function findDiscount(LineInput $input): ?DiscountRule
    {
        foreach ($input->discounts as $rule) {
            if ($rule->id === $input->discountId) {
                return $rule;
            }
        }

        return null;
    }
}
