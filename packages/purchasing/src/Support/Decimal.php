<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Support;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;

/**
 * The one amount parser {@see Money} shares with the rest of the package (the
 * customers `Decimal` precedent, T-028 Ruling 3).
 *
 * **Never rounds.** An operand with a scale of 4 or less parses exactly; a 5th
 * decimal place is refused rather than rounded. A float never reaches here as a
 * float: {@see Money::of()} turns one into a string first, and a caller in a
 * `declare(strict_types=1)` file that hands this class a float gets a `TypeError`.
 */
final class Decimal
{
    /** `DECIMAL(18,4)`'s largest magnitude: 14 integer digits, 4 fraction digits. */
    public const MAX = '99999999999999.9999';

    private const UNSIGNED_PATTERN = '/^\d+(\.\d{1,4})?$/';

    private const SIGNED_PATTERN = '/^-?\d+(\.\d{1,4})?$/';

    /**
     * An int, or a string matching `^\d+(\.\d{1,4})?$`; anything else is null.
     */
    public static function parseUnsigned(int|string $value): ?BigDecimal
    {
        return self::parse($value, self::UNSIGNED_PATTERN);
    }

    /**
     * An int, or a string matching `^-?\d+(\.\d{1,4})?$`; anything else is null.
     */
    public static function parseSigned(int|string $value): ?BigDecimal
    {
        return self::parse($value, self::SIGNED_PATTERN);
    }

    private static function parse(int|string $value, string $pattern): ?BigDecimal
    {
        if (is_int($value)) {
            return BigDecimal::of($value);
        }

        if (preg_match($pattern, $value) !== 1) {
            return null;
        }

        try {
            return BigDecimal::of($value);
        } catch (MathException) {
            return null;
        }
    }

    /**
     * Whether `$value` fits `DECIMAL(18,4)`: at most 14 integer digits.
     */
    public static function fitsEighteenFour(BigDecimal $value): bool
    {
        return $value->abs()->compareTo(BigDecimal::of(self::MAX)) <= 0;
    }

    /**
     * The canonical plain 4-decimal string: never rounds (`Unnecessary` throws
     * rather than passing silently), and a zero is never negative.
     */
    public static function canonical(BigDecimal $value): string
    {
        if ($value->isZero()) {
            return '0.0000';
        }

        return $value->toScale(4, RoundingMode::Unnecessary)->__toString();
    }
}
