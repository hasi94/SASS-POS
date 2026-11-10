<?php

declare(strict_types=1);

namespace Flexipos\Customers\Support;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;

/**
 * The one amount parser both ledger calculators share (T-028 plan Ruling 3).
 *
 * **Never rounds.** An operand with a scale of 4 or less parses exactly; a
 * 5th decimal place is refused rather than rounded, which is the entire
 * reason this parser exists instead of a bare `BigDecimal::of()` call. A
 * float never reaches here as a float - `CreditLedgerCalculator::apply()`
 * and `LoyaltyCalculator::apply()` both type their amount parameters
 * `int|string`, so a caller in a `declare(strict_types=1)` file that hands
 * one a float gets a `TypeError` at the call boundary, the same
 * `SnapshotCalculator`/`ResolvedFactor` precedent (T-027) already
 * establishes for a conversion factor or a quantity. Nothing here is a
 * second, looser entry point for one.
 */
final class Decimal
{
    /** `DECIMAL(18,4)`'s largest magnitude: 14 integer digits, 4 fraction digits. */
    public const MAX = '99999999999999.9999';

    private const UNSIGNED_PATTERN = '/^\d+(\.\d{1,4})?$/';

    private const SIGNED_PATTERN = '/^-?\d+(\.\d{1,4})?$/';

    /**
     * Parse an unsigned amount (`debit`/`credit`): an int, or a string
     * matching `^\d+(\.\d{1,4})?$`. Anything else, including a blank
     * string, an exponent or a 5th decimal place, returns null.
     */
    public static function parseUnsigned(int|string $value): ?BigDecimal
    {
        return self::parse($value, self::UNSIGNED_PATTERN);
    }

    /**
     * Parse a signed amount (`points`): an int, or a string matching
     * `^-?\d+(\.\d{1,4})?$`.
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
     * Whether `$value` fits `DECIMAL(18,4)`: at most 14 integer digits, and
     * (by construction of every caller here) a scale of 4 or less.
     */
    public static function fitsEighteenFour(BigDecimal $value): bool
    {
        return $value->abs()->compareTo(BigDecimal::of(self::MAX)) <= 0;
    }

    /**
     * The canonical plain 4-decimal string (Ruling 3.4): never rounds
     * (`RoundingMode::Unnecessary`, so an accidental need to round throws
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
