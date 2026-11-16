<?php

declare(strict_types=1);

namespace Flexipos\Sales\Shifts;

use Flexipos\Sales\Sales\SaleStatus;
use Flexipos\Sales\Support\Lookup;
use Flexipos\Sales\Support\Money;
use Flexipos\Sales\Tenders\MethodType;
use Illuminate\Database\Connection;

/**
 * What a shift's drawer should hold (AC-34, D-39/F-15).
 *
 * `expected cash = opening cash + every cash-method tender + every cash_in and
 * float_in movement - every cash_out, float_out, drop, petty_cash and
 * bank_deposit movement`. A refund's tender is stored negative, so summing the
 * signed `amount` is what subtracts it.
 *
 * **Which tenders count.** Only those on a sale that is `completed`,
 * `refunded` or `partially_refunded` (a refund document is `completed`): a
 * `draft`, `pending_payment` or `held` sale has taken no money yet, and a
 * `voided` one nets to zero. Counting a held sale's tender would put cash in the
 * drawer that is not in it (mutation M2). The figure is the tender's `amount`,
 * never `tendered`, which would count the change that already left the drawer.
 *
 * It orders nothing and limits nothing: it is a sum. It reads only this
 * package's tables, as plain reads; the caller decides under which lock.
 */
final class ShiftTotals
{
    public static function expectedCash(Connection $connection, string $shiftId): string
    {
        $shift = Lookup::any($connection, 'cash_shifts', $shiftId, 'cash shift');

        $expected = Money::of($shift->opening_cash);

        $tenders = $connection->table('sale_tenders')
            ->join('sales', 'sales.id', '=', 'sale_tenders.sale_id')
            ->where('sales.shift_id', (string) $shift->id)
            ->where('sale_tenders.method_type', MethodType::Cash->value)
            ->whereIn('sales.status', SaleStatus::settled())
            ->pluck('sale_tenders.amount');

        $expected = Money::add($expected, Money::sum($tenders));

        $movements = $connection->table('cash_movements')
            ->where('shift_id', (string) $shift->id)
            ->get(['type', 'amount']);

        foreach ($movements as $movement) {
            $type = MovementType::from((string) $movement->type);
            $amount = Money::of($movement->amount);

            $expected = $type->addsToDrawer()
                ? Money::add($expected, $amount)
                : Money::subtract($expected, $amount);
        }

        return $expected;
    }
}
