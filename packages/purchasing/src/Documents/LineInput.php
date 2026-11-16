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
