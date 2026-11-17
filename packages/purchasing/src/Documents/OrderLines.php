<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Documents;

use Flexipos\Purchasing\Exceptions\PurchasingRefusal;
use Flexipos\Purchasing\Exceptions\PurchasingRefused;
use Flexipos\Purchasing\Support\Identifier;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Connection;

/**
 * What the order slices share about an order's lines and its totals (T-034 AC-42
 * to AC-51).
 *
 * Nothing here opens a transaction or takes the header lock: the slice owns both,
 * in the order of Ruling 7 (the header first), and calls these with the header
 * already held. **Totals are never computed here**: {@see OrderRollup} sums the
 * stored line figures and the header takes the sums (AC-45); a sum that cannot fit
 * `DECIMAL(18,4)` is `out_of_range` and refuses the change that caused it (AC-47).
 */
final class OrderLines
{
    public function __construct(private readonly ConfigRepository $config) {}

    /**
     * Room for one more line (AC-51: the 201st is refused).
     *
     * @throws PurchasingRefused too_many_lines
     */
    public function assertRoom(Connection $connection, string $orderId): void
    {
        $max = (int) $this->config->get('purchasing.max_lines', 200);

        if ($connection->table('purchase_order_items')->where('order_id', $orderId)->count() >= $max) {
            throw PurchasingRefused::because(PurchasingRefusal::TooManyLines, "A purchase order holds at most {$max} lines.");
        }
    }

    /**
     * The next line number: one above the highest, so a removed line leaves a gap
     * and a number is never reused within an order.
     */
    public static function nextLineNo(Connection $connection, string $orderId): int
    {
        return ((int) $connection->table('purchase_order_items')->where('order_id', $orderId)->max('line_no')) + 1;
    }

    /**
     * One line of this order, optionally `FOR UPDATE`; a line of another order is
     * not found through this one.
     *
     * @throws PurchasingRefused validation (malformed id), not_found
     */
    public static function line(Connection $connection, string $orderId, mixed $lineId, bool $lock = false): object
    {
        $id = Identifier::fold($lineId, 'line_id');

        $query = $connection->table('purchase_order_items')->where('id', $id)->where('order_id', $orderId);

        if ($lock) {
            $query->lockForUpdate();
        }

        $line = $query->first();

        if ($line === null) {
            throw PurchasingRefused::notFound("Purchase order [{$orderId}] has no line [{$id}].");
        }

        return $line;
    }

    /**
     * The header totals the stored lines and this shipping give.
     *
     * @return array{subtotal: string, discount_total: string, tax_total: string, total: string}
     *
     * @throws PurchasingRefused out_of_range
     */
    public static function totals(Connection $connection, string $orderId, string $shipping): array
    {
        return OrderRollup::totals(
            $connection->table('purchase_order_items')
                ->where('order_id', $orderId)
                ->get(['subtotal', 'discount_amount', 'tax_amount', 'line_total'])
                ->map(static fn (object $row): array => (array) $row)
                ->all(),
            $shipping,
        );
    }

    /**
     * Write the header totals the stored lines give, and move `updated_at` and
     * `updated_by` (a line change is a change to the document).
     *
     * @throws PurchasingRefused out_of_range
     */
    public static function retotal(Connection $connection, string $orderId, string $shipping, string $actorId, string $now): void
    {
        $connection->table('purchase_orders')->where('id', $orderId)->update(array_merge(
            self::totals($connection, $orderId, $shipping),
            ['updated_at' => $now, 'updated_by' => $actorId],
        ));
    }

    /**
     * Move the header's `updated_at` and `updated_by` without touching a figure.
     */
    public static function touch(Connection $connection, string $orderId, string $actorId, string $now): void
    {
        $connection->table('purchase_orders')->where('id', $orderId)->update([
            'updated_at' => $now,
            'updated_by' => $actorId,
        ]);
    }
}
