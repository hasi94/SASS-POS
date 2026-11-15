<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Pricing;

/**
 * The one writer of `discounts.used_count` (T-030 AC-41, D-37, F-12, F-25;
 * T-029 plan section 10's debt to the sales task).
 *
 * **Exactly `usage_limit` uses succeed.** `consume()` refuses when the stored
 * count already equals (or exceeds) the limit, so a discount with a limit of 2
 * is consumed twice and refused the third time. `PricingEngine` refuses at the
 * same boundary when it quotes, and the two must agree.
 *
 * **It checks existence and the limit and nothing else** (T-030 Q-11, AC-58):
 * `is_active` and the start/end window are facts the engine checked when it
 * priced the line, and a discount deactivated between the quote and the
 * completion is still consumed and honoured. A soft-deleted discount is
 * `unknown_discount`.
 *
 * **Call it inside the completing transaction**, after the locks that come
 * before it in the global lock order and **once per distinct discount, in
 * ascending id order**: it takes the discount row under a row lock, and two
 * completions consuming the same two discounts in opposite orders would
 * deadlock. A refused call writes nothing. Usage is never released (a void or a
 * refund leaves the count where it is).
 *
 * Implemented in `packages/pricing`, the owner of `discounts`.
 */
interface DiscountUsage
{
    /**
     * Take one use of the discount.
     *
     * @throws PricingRefused `unknown_discount`, `usage_limit_reached`, or `out_of_range` when the stored count is already at the column's maximum
     */
    public function consume(string $discountId): void;
}
