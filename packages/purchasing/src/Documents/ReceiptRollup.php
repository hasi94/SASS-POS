<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Documents;

use Flexipos\Purchasing\Exceptions\PurchasingRefused;
use Flexipos\Purchasing\Support\Money;

/**
 * How an order learns what has arrived (T-034 AC-69 to AC-74, `docs/database.md`
 * §6.6): **base to base, on the stored four-decimal values, never on
 * `received_quantity`**.
 *
 * A delivery in a different unit from the order cannot close a half-empty order
 * because the only columns compared are `received_base_quantity` and
 * `base_quantity`; `received_quantity` is a display figure in the line's own unit
 * and is never read here (AC-70). The comparison is exact (`239.9999` is not
 * `240.0000`, AC-72) and uses `Money`'s `BigDecimal`, because a `DECIMAL` is a
 * string on MySQL and an int or a float on the SQLite stand-in.
 *
 * Pure: it reads no connection.
 */
final class ReceiptRollup
{
    /**
     * The status an order has, from the stored base sums of every line: all zero
     * is `approved`, every line complete is `received`, anything else is
     * `partially_received` (Ruling 4, AC-69, AC-71, AC-74). Zero beats complete,
     * so an order whose every line is at zero is `approved` even if a line's
     * ordered base were itself zero.
     *
     * @param  iterable<array<string, mixed>>  $lines  rows carrying `base_quantity` and `received_base_quantity`
     */
    public static function status(iterable $lines): OrderStatus
    {
        $anyReceived = false;
        $allComplete = true;

        foreach ($lines as $line) {
            $received = Money::of(self::value($line, 'received_base_quantity'));

            if (! Money::isZero($received)) {
                $anyReceived = true;
            }

            if (! self::isComplete(self::value($line, 'base_quantity'), $received)) {
                $allComplete = false;
            }
        }

        if (! $anyReceived) {
            return OrderStatus::Approved;
        }

        return $allComplete ? OrderStatus::Received : OrderStatus::PartiallyReceived;
    }

    /**
     * Whether a line is complete: the received base is at least the ordered base
     * (AC-72, AC-73). Over-receipt counts as complete.
     */
    public static function isComplete(int|float|string $baseQuantity, int|float|string $receivedBaseQuantity): bool
    {
        return Money::compare(Money::of($receivedBaseQuantity), Money::of($baseQuantity)) >= 0;
    }

    /**
     * A received column after a delta (AC-74): the sum, which must not drop below
     * zero and must fit `DECIMAL(18,4)`.
     *
     * @throws PurchasingRefused out_of_range
     */
    public static function apply(int|float|string $current, string $delta): string
    {
        $next = Money::add(Money::of($current), $delta);

        if (Money::compare($next, Money::ZERO) < 0) {
            throw PurchasingRefused::outOfRange("A received quantity cannot go below zero ([{$current}] with a delta of [{$delta}]).");
        }

        if (! Money::fits($next)) {
            throw PurchasingRefused::outOfRange('The received quantity would not fit DECIMAL(18,4).');
        }

        return $next;
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
