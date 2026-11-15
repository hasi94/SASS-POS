<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Engine;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Flexipos\Contracts\Pricing\PricingRefused;
use Flexipos\Contracts\Pricing\Shape;

/**
 * The arithmetic rules every pure engine class shares (T-029 plan Ruling 3).
 *
 * `brick/math` `BigDecimal` throughout, never a float. The rules, which the
 * TypeScript twin (FP-432) must match exactly:
 *
 * 1. Inputs are strings. Money and quantity are non-negative with at most 14
 *    integer and 4 fraction digits, a rate at most 5 and 4, a factor at most 12
 *    and 6 and at least 1. Anything else, including a float, an exponent, a
 *    fifth decimal, a blank or a negative, is `invalid_amount`
 *    ({@see Shape}).
 * 2. Every named step rounds half-up to four decimals, and the rounded value is
 *    what the next step consumes. The named steps are exactly: a derived price,
 *    a list-percent price, a subtotal, a discount amount, each tax amount and
 *    the inclusive base. Adding and subtracting four-decimal values is exact.
 * 3. A percentage is `x * p / 100`, multiplied exactly and rounded once, never
 *    `x * (p / 100 rounded)`.
 * 4. A result above `99999999999999.9999` is `out_of_range`. Output is canonical
 *    (four decimals) and a zero is never negative.
 */
final class Decimal
{
    public static function money(mixed $value, string $what): BigDecimal
    {
        return BigDecimal::of(Shape::money($value, $what));
    }

    public static function rate(mixed $value, string $what): BigDecimal
    {
        return BigDecimal::of(Shape::rate($value, $what));
    }

    public static function factor(mixed $value, string $what): BigDecimal
    {
        return BigDecimal::of(Shape::factor($value, $what));
    }

    /**
     * Round a named step to four decimals, half-up, and refuse a value that no
     * longer fits `DECIMAL(18,4)`.
     *
     * @throws PricingRefused
     */
    public static function round(BigDecimal $value): BigDecimal
    {
        $rounded = $value->toScale(4, RoundingMode::HalfUp);

        self::checkRange($rounded);

        return $rounded;
    }

    /**
     * `amount * percent / 100`, exact, rounded once.
     *
     * @throws PricingRefused
     */
    public static function percentOf(BigDecimal $amount, BigDecimal $percent): BigDecimal
    {
        $exact = $amount->multipliedBy($percent)->dividedBy(100, 4, RoundingMode::HalfUp);

        self::checkRange($exact);

        return $exact;
    }

    /**
     * The canonical output string: four decimals, in range, never negative.
     *
     * A negative here is an engine defect, not a caller mistake, but it is still
     * refused by name rather than printed.
     *
     * @throws PricingRefused
     */
    public static function text(BigDecimal $value): string
    {
        try {
            $scaled = $value->toScale(4, RoundingMode::Unnecessary);
        } catch (MathException) {
            throw PricingRefused::invalidAmount("The value [{$value}] has more than four decimals; an unrounded value must never reach an output.");
        }

        self::checkRange($scaled);

        if ($scaled->isNegative()) {
            throw PricingRefused::outOfRange("The value [{$scaled}] is negative.");
        }

        return (string) $scaled;
    }

    /**
     * @throws PricingRefused
     */
    private static function checkRange(BigDecimal $value): void
    {
        if ($value->abs()->compareTo(Shape::MAX_AMOUNT) > 0) {
            throw PricingRefused::outOfRange("The value [{$value}] does not fit DECIMAL(18,4).");
        }
    }
}
