<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Documents;

use Flexipos\Purchasing\Exceptions\PurchasingRefusal;
use Flexipos\Purchasing\Exceptions\PurchasingRefused;
use Flexipos\Purchasing\Support\Money;
use Illuminate\Database\Connection;

/**
 * Whether an order has ever received anything (T-034 AC-62, AC-64, D-45).
 *
 * Reopen and cancel are refused `already_received` when **any** line holds a
 * received base quantity **or any receipt-application row exists**, even one
 * reversed back to zero: a receipt that has happened once has told stock about the
 * order, and the order is closed, not undone. Read under the header lock.
 */
final class OrderReceipts
{
    /**
     * @throws PurchasingRefused already_received
     */
    public static function assertNone(Connection $connection, string $orderId, string $number, string $instead): void
    {
        $applied = $connection->table('purchase_order_receipt_applications')->where('order_id', $orderId)->exists();

        $received = false;

        foreach ($connection->table('purchase_order_items')->where('order_id', $orderId)->pluck('received_base_quantity') as $quantity) {
            if (! Money::isZero(Money::of($quantity))) {
                $received = true;
            }
        }

        if ($applied || $received) {
            throw PurchasingRefused::because(
                PurchasingRefusal::AlreadyReceived,
                "Purchase order [{$number}] has had goods received against it. {$instead}",
            );
        }
    }
}
