<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Engine;

use Brick\Math\BigDecimal;
use Flexipos\Contracts\Pricing\BillDiscount;
use Flexipos\Contracts\Pricing\PricingRefused;
use Flexipos\Contracts\Pricing\Shape;

/**
 * When a discount applies and how much it takes off, a pure function (AC-20,
 * AC-22.2, AC-23; D-12, D-18, D-19).
 *
 * **The refusal order is fixed**, each named, and every time check runs against
 * the **document instant** passed in, never the clock, so a fixture replays
 * identically next year:
 *
 * 1. `unknown_discount`: no rule;
 * 2. `discount_scope_mismatch`: a bill discount given to a line, or the reverse;
 * 3. `inactive`, then `not_yet_started` (before `startsAt`), then `expired`
 *    (after `endsAt`; exactly at `endsAt` is accepted), then
 *    `usage_limit_reached` (`usedCount >= usageLimit`);
 * 4. `discount_not_allowed`: the product disallows discounts (item only);
 * 5. `not_applicable`: `applies_to` names ids and the line is in none (item only);
 * 6. `below_min_amount`: the subtotal, or the bill base, is under `minAmount`;
 * 7. `unsupported_discount_type`: `buy_x_get_y`.
 *
 * **The amount:** a percentage is `round(base x value / 100, 4)` capped at
 * `maxDiscount`; a fixed discount is `min(value, base)`, so a line is never
 * negative. `approval` is never evaluated (D-8).
 */
final class DiscountCalculator
{
    /**
     * The discount amount for one line, against that line's subtotal.
     *
     * @throws PricingRefused
     */
    public static function forLine(?DiscountRule $rule, string $at, ProductFacts $product, BigDecimal $subtotal): BigDecimal
    {
        $rule = self::available($rule, DiscountScope::Item, $at);

        if (! $product->allowDiscount) {
            throw PricingRefused::discountNotAllowed("Product [{$product->productId}] does not allow discounts.");
        }

        if (! self::targets($rule, $product)) {
            throw PricingRefused::notApplicable("Discount [{$rule->id}] does not apply to product [{$product->productId}].");
        }

        return self::amount($rule, $subtotal, 'subtotal');
    }

    /**
     * A bill discount against the summed discounted (pre-tax) subtotals.
     *
     * @param  list<string>  $discountedSubtotals
     *
     * @throws PricingRefused
     */
    public static function forBill(?DiscountRule $rule, string $at, array $discountedSubtotals): BillDiscount
    {
        $rule = self::available($rule, DiscountScope::Bill, $at);

        $base = BigDecimal::zero();

        foreach ($discountedSubtotals as $subtotal) {
            $base = $base->plus(Decimal::money($subtotal, 'discountedSubtotal'));
        }

        $amount = self::amount($rule, Decimal::round($base), 'bill base');

        return new BillDiscount($rule->id, Decimal::text($base), Decimal::text($amount));
    }

    /**
     * Checks 1 to 3 (and the document instant's shape).
     *
     * @throws PricingRefused
     */
    private static function available(?DiscountRule $rule, DiscountScope $scope, string $at): DiscountRule
    {
        Shape::instant($at, 'at');

        if ($rule === null) {
            throw PricingRefused::unknownDiscount('No discount resolves to the requested id.');
        }

        if ($rule->scope !== $scope) {
            throw PricingRefused::discountScopeMismatch("Discount [{$rule->id}] has scope [{$rule->scope->value}] and cannot be applied to a {$scope->value}.");
        }

        if (! $rule->isActive) {
            throw PricingRefused::inactive("Discount [{$rule->id}] is not active.");
        }

        // Fixed-width UTC instants: string order is time order.
        if ($rule->startsAt !== null && $at < $rule->startsAt) {
            throw PricingRefused::notYetStarted("Discount [{$rule->id}] starts at {$rule->startsAt}.");
        }

        if ($rule->endsAt !== null && $at > $rule->endsAt) {
            throw PricingRefused::expired("Discount [{$rule->id}] ended at {$rule->endsAt}.");
        }

        if ($rule->usageLimit !== null && $rule->usedCount >= $rule->usageLimit) {
            throw PricingRefused::usageLimitReached("Discount [{$rule->id}] has been used {$rule->usedCount} of {$rule->usageLimit} times.");
        }

        return $rule;
    }

    private static function targets(DiscountRule $rule, ProductFacts $product): bool
    {
        if ($rule->targetsEverything()) {
            return true;
        }

        return in_array($product->productId, $rule->products, true)
            || ($product->categoryId !== null && in_array($product->categoryId, $rule->categories, true))
            || ($product->brandId !== null && in_array($product->brandId, $rule->brands, true));
    }

    /**
     * Checks 6 and 7, then the amount.
     *
     * @throws PricingRefused
     */
    private static function amount(DiscountRule $rule, BigDecimal $base, string $baseName): BigDecimal
    {
        if ($rule->minAmount !== null && $base->compareTo($rule->minAmount) < 0) {
            throw PricingRefused::belowMinAmount("The {$baseName} [{$base}] is below the minimum [{$rule->minAmount}] of discount [{$rule->id}].");
        }

        $value = Decimal::money($rule->value, 'value');

        $amount = match ($rule->type) {
            DiscountType::Percentage => self::capped(Decimal::percentOf($base, $value), $rule),
            DiscountType::Fixed => $value->compareTo($base) < 0 ? $value : $base,
            DiscountType::BuyXGetY => throw PricingRefused::unsupportedDiscountType("Discount [{$rule->id}] is buy_x_get_y, which has no arithmetic yet."),
        };

        return Decimal::round($amount);
    }

    private static function capped(BigDecimal $amount, DiscountRule $rule): BigDecimal
    {
        if ($rule->maxDiscount === null) {
            return $amount;
        }

        $cap = Decimal::money($rule->maxDiscount, 'maxDiscount');

        return $amount->compareTo($cap) > 0 ? $cap : $amount;
    }
}
