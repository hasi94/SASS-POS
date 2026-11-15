<?php

declare(strict_types=1);

namespace Flexipos\Sales\Sales;

use Flexipos\Sales\Exceptions\SalesRefusal;
use Flexipos\Sales\Exceptions\SalesRefused;
use Flexipos\Sales\Support\Lookup;
use Illuminate\Database\Connection;

/**
 * The locks and state reads every sale write shares (T-030 Ruling 7, R-5).
 *
 * **The order is the point.** A write that attributes a sale to a shift takes
 * the shift first (a shared lock, by primary key), then the sale; so does every
 * writer, and `CloseCashShift` takes the shift exclusively, so a sale, a tender
 * or a movement can never land on a shift that is closing. The shift id is read
 * before the transaction ({@see self::openShiftIdOf()}, a plain read), and locked
 * by id inside it ({@see self::lockOpenShift()}), where its status and its
 * terminal are checked again: a plain read decides nothing.
 */
final class SaleLocks
{
    /**
     * The id of the terminal's open shift, read before any transaction opens.
     * A plain read on purpose: the lock comes later, by primary key.
     */
    public static function openShiftIdOf(Connection $connection, string $terminalId): ?string
    {
        $id = $connection->table('cash_shifts')
            ->where('terminal_id', $terminalId)
            ->where('status', 'open')
            ->value('id');

        return $id === null ? null : strtoupper((string) $id);
    }

    /**
     * Lock the shift (shared, by id) and check it is still the terminal's open one.
     *
     * @throws SalesRefused no_open_shift
     */
    public static function lockOpenShift(Connection $connection, string $shiftId, string $terminalId): object
    {
        $shift = $connection->table('cash_shifts')->where('id', $shiftId)->sharedLock()->first();

        if ($shift === null || (string) $shift->status !== 'open' || strtoupper((string) $shift->terminal_id) !== $terminalId) {
            throw SalesRefused::because(SalesRefusal::NoOpenShift, "Terminal [{$terminalId}] has no open cash shift.");
        }

        return $shift;
    }

    /**
     * The sale, `FOR UPDATE`, by primary key.
     *
     * @throws SalesRefused notFound
     */
    public static function lockSale(Connection $connection, string $saleId): object
    {
        return Lookup::any($connection, 'sales', $saleId, 'sale', true);
    }

    /**
     * The status the sale was last held from, or null when it was never held.
     * Read under the sale lock, so it is the answer for this write.
     */
    public static function heldFrom(Connection $connection, string $saleId): ?string
    {
        $from = $connection->table('sale_holds')
            ->where('sale_id', $saleId)
            ->where('action', 'hold')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->value('from_status');

        return $from === null ? null : (string) $from;
    }

    /**
     * Whether a sale's lines may change: `draft`, or `held` after being held
     * from `draft` (AC-6, F-10). A sale held from `pending_payment` has tenders
     * and its lines stay locked.
     */
    public static function linesEditable(Connection $connection, object $sale): bool
    {
        $status = (string) $sale->status;

        if ($status === SaleStatus::Draft->value) {
            return true;
        }

        return $status === SaleStatus::Held->value
            && self::heldFrom($connection, strtoupper((string) $sale->id)) === SaleStatus::Draft->value;
    }

    /**
     * @throws SalesRefused sale_not_editable
     */
    public static function assertLinesEditable(Connection $connection, object $sale): void
    {
        if (! self::linesEditable($connection, $sale)) {
            throw SalesRefused::because(SalesRefusal::SaleNotEditable, "Sale [{$sale->id}] is [{$sale->status}] and its lines cannot change.");
        }
    }
}
