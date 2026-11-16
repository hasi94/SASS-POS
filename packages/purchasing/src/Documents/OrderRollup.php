<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Documents;

use Flexipos\Purchasing\Exceptions\PurchasingRefused;
use Flexipos\Purchasing\Support\Money;

/**
 * An order's header totals from its stored lines (T-034 AC-45, AC-47): the sums,
 * and nothing else.
 *
 * `subtotal` is the sum of line subtotals, `discount_total` of line discounts,
 * `tax_total` of line `tax_amount`, and `total` the sum of `line_total` plus
 * `shipping`. An order with no lines has every total `0.0000` plus shipping. A
 * figure that cannot fit `DECIMAL(18,4)` is `out_of_range`, and the slice that
 * asked refuses the line that caused it (AC-47). Pure: it sums strings, it never
 * rounds, and it never reads a connection.
 */
final class OrderRollup
{
    /**
     * @param  iterable<array<string, mixed>>  $lines  rows carrying `subtotal`, `discount_amount`, `tax_amount`, `line_total`
     * @return array{subtotal: string, discount_total: string, tax_total: string, total: string}
     *
     * @throws PurchasingRefused out_of_range
     */
    public static function totals(iterable $lines, string $shipping): array
    {
        $subtotal = [];
        $discount = [];
        $tax = [];
        $lineTotals = [];

        foreach ($lines as $line) {
            $subtotal[] = self::value($line, 'subtotal');
            $discount[] = self::value($line, 'discount_amount');
            $tax[] = self::value($line, 'tax_amount');
            $lineTotals[] = self::value($line, 'line_total');
        }

        $totals = [
            'subtotal' => Money::sum($subtotal),
            'discount_total' => Money::sum($discount),
            'tax_total' => Money::sum($tax),
            'total' => Money::add(Money::sum($lineTotals), Money::of($shipping)),
        ];

        foreach ($totals as $name => $amount) {
            if (! Money::fits($amount)) {
                throw PurchasingRefused::outOfRange("The order {$name} would not fit DECIMAL(18,4).");
            }
        }

        return $totals;
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private static function value(array $line, string $column): int|float|string
    {
        $value = $line[$column] ?? null;

        if (! is_int($value) && ! is_float($value) && ! is_string($value)) {
            throw PurchasingRefused::validation("A line row has no usable [{$column}].");
        }

        return $value;
    }
}
