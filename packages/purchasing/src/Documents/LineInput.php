<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Documents;

use Flexipos\Purchasing\Exceptions\PurchasingRefused;
use Flexipos\Purchasing\Support\Money;

/**
 * A quantity or a cost as the caller submits it (T-034 AC-23, AC-46, AC-47).
 *
 * Only a decimal **string** or an **int** of at most four decimals is a number
 * here; a float, a negative, text and null are `validation`. A well-formed number
 * of more than 14 integer digits is `out_of_range`: it is a number, the column
 * cannot hold it. A quantity must be above zero; a cost may be zero (D-28).
 */
final class LineInput
{
    /**
     * @throws PurchasingRefused validation, out_of_range
     */
    public static function quantity(mixed $submitted, string $name = 'quantity'): string
    {
        $quantity = self::parse($submitted, $name);

        if (Money::isZero($quantity)) {
            throw PurchasingRefused::validation("The {$name} must be greater than zero.");
        }

        return $quantity;
    }

    /**
     * A cost: null is "no cost given", zero is accepted.
     *
     * @throws PurchasingRefused validation, out_of_range
     */
    public static function cost(mixed $submitted, string $name): ?string
    {
        if ($submitted === null) {
            return null;
        }

        return self::parse($submitted, $name);
    }

    /**
     * A line discount, 0.00 to 100.00 with at most two decimals (AC-42, D-25),
     * returned as the stored four-decimal string. Null and absent are the caller's
     * to default; a float, a negative, three decimals and 100.01 are `validation`.
     *
     * @throws PurchasingRefused validation
     */
    public static function discountPercent(mixed $submitted): string
    {
        if (is_int($submitted)) {
            $submitted = (string) $submitted;
        }

        if (! is_string($submitted) || preg_match('/^\d{1,3}(\.\d{1,2})?$/D', $submitted) !== 1 || Money::compare($submitted, '100') > 0) {
            throw PurchasingRefused::validation('The discount_percent must be a decimal string from 0.00 to 100.00 with at most 2 decimals.');
        }

        return Money::of($submitted);
    }

    /**
     * @throws PurchasingRefused validation, out_of_range
     */
    private static function parse(mixed $submitted, string $name): string
    {
        if (is_int($submitted)) {
            $submitted = (string) $submitted;
        }

        if (! is_string($submitted) || preg_match('/^(\d+)(\.\d{1,4})?$/D', $submitted, $parts) !== 1) {
            throw PurchasingRefused::validation("The {$name} must be a non-negative decimal string with at most 4 decimals.");
        }

        if (strlen(ltrim($parts[1], '0')) > 14) {
            throw PurchasingRefused::outOfRange("The {$name} does not fit DECIMAL(18,4).");
        }

        return Money::of($submitted);
    }
}
