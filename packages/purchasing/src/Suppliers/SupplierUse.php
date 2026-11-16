<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Suppliers;

use Flexipos\Purchasing\Documents\OrderStatus;
use Flexipos\Purchasing\Documents\RequestStatus;
use Illuminate\Database\Connection;

/**
 * Whether a supplier still holds up work (T-034 AC-14, D-7).
 *
 * A request names a supplier only through its lines, so a request blocks the
 * delete while it is live (`draft`, `pending_approval`, `approved`) and any of its
 * lines names the supplier. An order blocks it while it is `draft`,
 * `pending_approval`, `approved`, `sent` or `partially_received`. The live sets
 * come from the two status enums, so a status added there is counted here without
 * a second list to forget.
 *
 * Plain reads: the delete slice has the supplier row locked `FOR UPDATE`, which is
 * the first and only lock it takes (Ruling 7, row 2).
 */
final class SupplierUse
{
    /**
     * A sentence naming what blocks the delete, or null when nothing does.
     */
    public static function blocker(Connection $connection, string $supplierId): ?string
    {
        $requestStatuses = array_map(
            static fn (RequestStatus $status): string => $status->value,
            array_values(array_filter(RequestStatus::cases(), static fn (RequestStatus $status): bool => $status->isLive())),
        );

        $orderStatuses = array_map(
            static fn (OrderStatus $status): string => $status->value,
            array_values(array_filter(OrderStatus::cases(), static fn (OrderStatus $status): bool => $status->isLive())),
        );

        $request = $connection->table('purchase_request_items as item')
            ->join('purchase_requests as request', 'request.id', '=', 'item.request_id')
            ->where('item.supplier_id', $supplierId)
            ->whereIn('request.status', $requestStatuses)
            ->value('request.number');

        if ($request !== null) {
            return "Purchase request [{$request}] still names this supplier and is not finished.";
        }

        $order = $connection->table('purchase_orders')
            ->where('supplier_id', $supplierId)
            ->whereIn('status', $orderStatuses)
            ->value('number');

        if ($order !== null) {
            return "Purchase order [{$order}] is for this supplier and is not finished.";
        }

        return null;
    }
}
