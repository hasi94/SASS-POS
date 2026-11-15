<?php

declare(strict_types=1);

namespace Flexipos\Sales\Sales;

use Flexipos\Sales\Exceptions\SalesRefused;
use Flexipos\Sales\Support\Money;
use Illuminate\Database\Connection;

/**
 * The header of a sale, re-summed from its lines (AC-8, R-11).
 *
 * **A plain sum of stored columns, never a re-derivation.** `sale_items.subtotal`
 * (before the discount: the net for an exclusive tax group, the gross for an
 * inclusive one) is why the rollup never has to look at tax inclusivity.
 * Consequences, both intended:
 *
 * - `total` is the sum of `line_total` and holds for every sale;
 * - `subtotal - discount_total + tax_total = total` holds only for an
 *   all-exclusive sale, so for an inclusive one `tax_total` is informational.
 *
 * **Summed in PHP, not with SQL `SUM()`.** On the SQLite stand-in a `DECIMAL`
 * comes back as an int or a float, and a float sum is not money (R-8); every
 * addend goes through {@see Money::of()} first. A sum that no longer fits
 * `DECIMAL(18,4)` is refused `out_of_range` before anything is written (AC-51).
 *
 * It orders nothing and limits nothing: it is a sum, and a sum does not depend
 * on row order.
 */
final class SaleRollup
{
    /**
     * @throws SalesRefused outOfRange
     */
    public static function of(Connection $connection, string $saleId): SaleTotals
    {
        $subtotal = Money::ZERO;
        $discount = Money::ZERO;
        $tax = Money::ZERO;
        $total = Money::ZERO;

        $lines = $connection->table('sale_items')
            ->where('sale_id', $saleId)
            ->get(['subtotal', 'discount_amount', 'tax_amount', 'line_total']);

        foreach ($lines as $line) {
            $subtotal = Money::add($subtotal, Money::of($line->subtotal));
            $discount = Money::add($discount, Money::of($line->discount_amount));
            $tax = Money::add($tax, Money::of($line->tax_amount));
            $total = Money::add($total, Money::of($line->line_total));
        }

        foreach (['subtotal' => $subtotal, 'discount_total' => $discount, 'tax_total' => $tax, 'total' => $total] as $name => $figure) {
            if (! Money::fits($figure)) {
                throw SalesRefused::outOfRange("The sale's {$name} would not fit DECIMAL(18,4).");
            }
        }

        return new SaleTotals($subtotal, $discount, $tax, $total);
    }
}
