<?php

declare(strict_types=1);

namespace Flexipos\Customers\Loyalty;

use Brick\Math\BigDecimal;
use Flexipos\Contracts\Customers\LoyaltyLedgerRefused;
use Flexipos\Contracts\Customers\LoyaltyTransactionType;
use Flexipos\Customers\Support\Decimal;

/**
 * The loyalty ledger arithmetic, pure (T-028 plan Ruling 5, Ruling 3;
 * AC-30 to AC-32, AC-41).
 *
 * **Pure.** No connection, no lock, no row - `LoyaltyWriter` supplies the
 * locked row's current `loyalty_points` balance and calls this with it.
 * Checks run in this fixed order:
 *
 * 1. parse `points`, signed (Ruling 3.1);
 * 2. zero gives `zero_points`;
 * 3. the entry type's hard sign (D-26, F-9) gives `wrong_sign` on a
 *    violation;
 * 4. `new = balance + points`, then the range check;
 * 5. only for `redeem`, `new < 0` gives `insufficient_points`. Redeeming
 *    down to exactly 0 is accepted. `expire`, `adjust` and `transfer` may
 *    take the balance below zero (D-13).
 */
final class LoyaltyCalculator
{
    /**
     * @throws LoyaltyLedgerRefused
     */
    public static function apply(string $balance, LoyaltyTransactionType $type, int|string $points): LoyaltyOutcome
    {
        $pointsAmount = Decimal::parseSigned($points);

        if ($pointsAmount === null) {
            throw LoyaltyLedgerRefused::invalidPoints("The points amount [{$points}] is not an int or a signed DECIMAL(18,4) string.");
        }

        if (! Decimal::fitsEighteenFour($pointsAmount)) {
            throw LoyaltyLedgerRefused::outOfRange("The points amount [{$points}] does not fit DECIMAL(18,4).");
        }

        if ($pointsAmount->isZero()) {
            throw LoyaltyLedgerRefused::zeroPoints('The points amount is zero.');
        }

        $positive = $pointsAmount->isPositive();

        $signOk = match ($type) {
            LoyaltyTransactionType::Earn => $positive,
            LoyaltyTransactionType::Redeem, LoyaltyTransactionType::Expire => ! $positive,
            LoyaltyTransactionType::Adjust, LoyaltyTransactionType::Transfer => true,
        };

        if (! $signOk) {
            throw LoyaltyLedgerRefused::wrongSign("The entry type [{$type->value}] does not allow a ".($positive ? 'positive' : 'negative').' points amount.');
        }

        $new = BigDecimal::of($balance)->plus($pointsAmount);

        if (! Decimal::fitsEighteenFour($new)) {
            throw LoyaltyLedgerRefused::outOfRange("The resulting balance [{$new}] does not fit DECIMAL(18,4).");
        }

        if ($type === LoyaltyTransactionType::Redeem && $new->isNegative()) {
            throw LoyaltyLedgerRefused::insufficientPoints("The resulting balance [{$new}] would be negative.");
        }

        return new LoyaltyOutcome(Decimal::canonical($new));
    }
}
