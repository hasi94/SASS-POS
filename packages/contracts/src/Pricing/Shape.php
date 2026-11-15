<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Pricing;

use InvalidArgumentException;

/**
 * The shape checks every pricing DTO and every pure-engine input shares
 * (T-029 plan Ruling 2, Ruling 3.1): one site, so the two layers cannot drift.
 *
 * **Shape only.** Nothing here rounds, resolves or looks anything up. Two
 * different failures, deliberately:
 *
 * - A malformed **amount** (money, quantity, rate, factor) is a named
 *   {@see PricingRefused} with `invalid_amount`, because an amount is data a
 *   caller may legitimately get wrong and must be told about by name.
 * - A malformed **id, date, instant or currency** is an
 *   `InvalidArgumentException` naming the field in brackets, the
 *   `UnitSnapshot` discipline: those are built by code, never typed by a
 *   person, so a wrong one is a caller bug.
 *
 * Amounts are accepted with one to four decimals and returned at the stored
 * scale of four, so `7.5` and `7.5000` are one value. Anything with a sign, an
 * exponent, a fifth decimal, a blank, or that is not a string at all (a float
 * or an int) is refused: a JavaScript number loses precision at exactly the
 * fourth decimal, which is why the fixtures are strings too (D-21).
 */
final class Shape
{
    /** The largest value a `DECIMAL(18,4)` column holds. */
    public const MAX_AMOUNT = '99999999999999.9999';

    private const MONEY_PATTERN = '/^(\d{1,14})(?:\.(\d{1,4}))?$/D';

    private const RATE_PATTERN = '/^(\d{1,5})(?:\.(\d{1,4}))?$/D';

    private const FACTOR_PATTERN = '/^(\d{1,12})(?:\.(\d{1,6}))?$/D';

    /**
     * A money or quantity amount, `DECIMAL(18,4)`, never negative.
     *
     * @throws PricingRefused
     */
    public static function money(mixed $value, string $what): string
    {
        return self::decimal($value, self::MONEY_PATTERN, 4, $what, 'a non-negative decimal of at most 14 integer and 4 fraction digits');
    }

    /**
     * A rate or percentage, `DECIMAL(9,4)`, never negative. `15.0000` means 15 %.
     *
     * @throws PricingRefused
     */
    public static function rate(mixed $value, string $what): string
    {
        return self::decimal($value, self::RATE_PATTERN, 4, $what, 'a non-negative decimal of at most 5 integer and 4 fraction digits');
    }

    /**
     * A unit factor, `DECIMAL(18,6)`, at least `1`.
     *
     * @throws PricingRefused
     */
    public static function factor(mixed $value, string $what): string
    {
        $canonical = self::decimal($value, self::FACTOR_PATTERN, 6, $what, 'a decimal of at most 12 integer and 6 fraction digits');

        if (str_starts_with($canonical, '0.')) {
            throw PricingRefused::invalidAmount("[{$what}] must be at least 1.");
        }

        return $canonical;
    }

    public static function ulid(string $value, string $what): string
    {
        if (preg_match('/^[0-9A-Za-z]{26}$/D', $value) !== 1) {
            throw new InvalidArgumentException("[{$what}] is not a 26-character ULID.");
        }

        return strtoupper($value);
    }

    public static function date(string $value, string $what): string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $parts) !== 1 || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            throw new InvalidArgumentException("[{$what}] must be a valid Y-m-d date.");
        }

        return $value;
    }

    /**
     * `YYYY-MM-DDTHH:MM:SS.mmmZ`, UTC, millisecond precision (D-21). The fixed
     * width is what lets two instants be compared as plain strings.
     */
    public static function instant(string $value, string $what): string
    {
        $valid = preg_match('/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})\.\d{3}Z$/D', $value, $parts) === 1
            && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
            && (int) $parts[4] < 24
            && (int) $parts[5] < 60
            && (int) $parts[6] < 60;

        if (! $valid) {
            throw new InvalidArgumentException("[{$what}] must be a valid UTC instant, YYYY-MM-DDTHH:MM:SS.mmmZ.");
        }

        return $value;
    }

    public static function currency(string $value, string $what): string
    {
        if (preg_match('/^[A-Z]{3}$/D', $value) !== 1) {
            throw new InvalidArgumentException("[{$what}] must be three upper-case letters.");
        }

        return $value;
    }

    private static function decimal(mixed $value, string $pattern, int $scale, string $what, string $expected): string
    {
        if (! is_string($value) || preg_match($pattern, $value, $parts) !== 1) {
            throw PricingRefused::invalidAmount("[{$what}] must be a string holding {$expected}.");
        }

        $integer = ltrim($parts[1], '0');

        return ($integer === '' ? '0' : $integer).'.'.str_pad($parts[2] ?? '', $scale, '0');
    }
}
