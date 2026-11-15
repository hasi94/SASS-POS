<?php

declare(strict_types=1);

namespace Flexipos\Sales\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * Every piece of arithmetic this package does itself (T-030 Ruling 3, AC-42).
 *
 * **One file on purpose.** The only things Sales computes are sums and
 * differences of stored 4-decimal amounts, and the one price-override product
 * `round(unitPrice x quantity, 4)`. None of it is tax, discount or factor
 * arithmetic, which stay in the pricing engine and the unit resolver. The static
 * source-rules scan (step 23) asserts that no other file in the package
 * multiplies, divides or calls `multipliedBy` or `dividedBy`, so a second place
 * that computes money has to be written here or fail the scan.
 *
 * Amounts are canonical 4-decimal strings (`7.5000`), never a float, and zero is
 * never `-0.0000`. {@see self::of()} is how a value read back from a row becomes
 * one (R-8): a `DECIMAL` is a string on MySQL and an int or a float on the
 * SQLite stand-in, and every decimal equality is {@see self::equals()}, never a
 * string comparison.
 */
final class Money
{
    public const ZERO = '0.0000';

    /**
     * A stored or submitted amount as a canonical 4-decimal string.
     *
     * @throws InvalidArgumentException when it is not a plain decimal of at most 4 places
     */
    public static function of(int|float|string $value): string
    {
        if (is_float($value)) {
            $value = number_format($value, 4, '.', '');
        }

        return Decimal::canonical(self::parse($value));
    }

    public static function add(string $a, string $b): string
    {
        return Decimal::canonical(self::parse($a)->plus(self::parse($b)));
    }

    public static function subtract(string $a, string $b): string
    {
        return Decimal::canonical(self::parse($a)->minus(self::parse($b)));
    }

    /**
     * The sign flip a ledger amount or a mirror tender needs (R-20).
     */
    public static function negate(string $a): string
    {
        return Decimal::canonical(self::parse($a)->negated());
    }

    public static function abs(string $a): string
    {
        return Decimal::canonical(self::parse($a)->abs());
    }

    /**
     * @param  iterable<int|float|string>  $values
     */
    public static function sum(iterable $values): string
    {
        $total = BigDecimal::zero();

        foreach ($values as $value) {
            $total = $total->plus(self::parse(self::of($value)));
        }

        return Decimal::canonical($total);
    }

    public static function compare(string $a, string $b): int
    {
        return self::parse($a)->compareTo(self::parse($b));
    }

    /**
     * Whether two amounts are the same number: `10` and `10.0000` are one value.
     */
    public static function equals(string $a, string $b): bool
    {
        return self::compare($a, $b) === 0;
    }

    public static function isZero(string $a): bool
    {
        return self::parse($a)->isZero();
    }

    public static function isPositive(string $a): bool
    {
        return self::parse($a)->isPositive();
    }

    public static function min(string $a, string $b): string
    {
        return self::compare($a, $b) <= 0 ? self::of($a) : self::of($b);
    }

    /**
     * Whether the amount fits `DECIMAL(18,4)`.
     */
    public static function fits(string $a): bool
    {
        return Decimal::fitsEighteenFour(self::parse($a));
    }

    /**
     * The one price-override product (AC-7, D-40): `round(unitPrice x quantity,
     * 4)`, HALF_UP.
     */
    public static function product(string $unitPrice, string $quantity): string
    {
        return Decimal::canonical(
            self::parse($unitPrice)->multipliedBy(self::parse($quantity))->toScale(4, RoundingMode::HalfUp)
        );
    }

    private static function parse(int|string $value): BigDecimal
    {
        return Decimal::parseSigned($value)
            ?? throw new InvalidArgumentException('An amount must be a plain decimal of at most 4 places.');
    }
}
