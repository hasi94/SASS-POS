<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Documents;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Flexipos\Purchasing\Exceptions\PurchasingRefused;

/**
 * An order's exchange rate, `DECIMAL(18,8)` (T-034 AC-41, D-33).
 *
 * A **positive** int or decimal string of at most 10 integer and 8 decimal digits;
 * a float, zero, a negative, text and null are `validation`. It is data: no
 * conversion happens in this task (D-20). {@see self::of()} is how a stored value
 * becomes the one eight-decimal string, because the SQLite stand-in hands it back
 * as an int or a float and MySQL as a string.
 */
final class ExchangeRate
{
    public const DEFAULT = '1.00000000';

    /**
     * @throws PurchasingRefused validation
     */
    public static function input(mixed $submitted, string $name = 'exchange_rate'): string
    {
        if (is_int($submitted)) {
            $submitted = (string) $submitted;
        }

        if (! is_string($submitted) || preg_match('/^\d{1,10}(\.\d{1,8})?$/D', $submitted) !== 1) {
            throw PurchasingRefused::validation("The {$name} must be a positive decimal string of at most 10 integer and 8 decimal digits.");
        }

        $rate = BigDecimal::of($submitted);

        if (! $rate->isPositive()) {
            throw PurchasingRefused::validation("The {$name} must be greater than zero.");
        }

        return $rate->toScale(8, RoundingMode::Unnecessary)->__toString();
    }

    /**
     * A stored rate as the eight-decimal string.
     */
    public static function of(int|float|string $stored): string
    {
        return BigDecimal::of((string) $stored)->toScale(8, RoundingMode::HalfUp)->__toString();
    }
}
