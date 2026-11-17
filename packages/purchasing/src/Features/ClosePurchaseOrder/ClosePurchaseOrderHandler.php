<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Features\ClosePurchaseOrder;

use Flexipos\Contracts\Access\PermissionResolver;
use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Purchasing\Documents\DocumentLocks;
use Flexipos\Purchasing\Documents\OrderEvents;
use Flexipos\Purchasing\Documents\OrderImage;
use Flexipos\Purchasing\Documents\OrderStatus;
use Flexipos\Purchasing\Documents\ReasonInput;
use Flexipos\Purchasing\Exceptions\PurchasingRefused;
use Flexipos\Purchasing\Registry\PurchasingAuditActions;
use Flexipos\Purchasing\Registry\PurchasingPermissions;
use Flexipos\Purchasing\Support\Authorise;
use Flexipos\Purchasing\Support\Fields;
use Flexipos\Purchasing\Support\Identifier;
use Flexipos\Purchasing\Support\Lookup;
use Flexipos\Purchasing\Support\TenantConnection;

/**
 * Close an order that has received something (T-034 AC-65, AC-67, AC-97; Rulings
 * 4, 11).
 *
 * Needs `purchasing.orders.close` at the order's stored branch and a `reason` of 1
 * to 500 characters (checked even for a repeat). Only `partially_received` and
 * `received` move to `closed`; every other status but `closed` is
 * `invalid_transition` naming it. The abandoned quantity on open lines is **left as
 * stored**: no line, no received column and no total is touched. One audit row and
 * the `purchase_order.closed` event, same transaction.
 *
 * **Idempotent by outcome:** closing a closed order succeeds, writes nothing and
 * returns `changed = false`.
 */
final class ClosePurchaseOrderHandler
{
    private const FIELDS = ['order_id', 'reason'];

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly AuditTrail $audit,
        private readonly PermissionResolver $permissions,
        private readonly OrderEvents $events,
    ) {}

    public function handle(ClosePurchaseOrder $command): ClosePurchaseOrderResult
    {
        $fields = $command->fields;

        Fields::only($fields, self::FIELDS);

        $orderId = Identifier::fold($fields['order_id'] ?? null, 'order_id');

        $connection = $this->tenant->get();

        $header = Lookup::any($connection, 'purchase_orders', $orderId, 'purchase order');
        $branchId = strtoupper((string) $header->branch_id);

        $actorId = Authorise::require($this->permissions, $command->actorId, PurchasingPermissions::ORDERS_CLOSE, $branchId);

        $reason = ReasonInput::required($fields);

        return $connection->transaction(function () use ($connection, $orderId, $branchId, $actorId, $reason): ClosePurchaseOrderResult {
            $before = OrderImage::header(DocumentLocks::order($connection, $orderId));
            $status = OrderStatus::from($before['status']);

            if ($status === OrderStatus::Closed) {
                return new ClosePurchaseOrderResult($orderId, false, $before);
            }

            $status->assertCanMoveTo(OrderStatus::Closed);

            $connection->table('purchase_orders')->where('id', $orderId)->update([
                'status' => OrderStatus::Closed->value,
                'updated_at' => $this->clock->timestamp(),
                'updated_by' => $actorId,
            ]);

            $after = OrderImage::header($connection->table('purchase_orders')->where('id', $orderId)->first()
                ?? throw PurchasingRefused::notFound("No purchase order has id [{$orderId}]."));

            $this->audit->record(
                PurchasingAuditActions::MODULE,
                $actorId,
                PurchasingAuditActions::ORDER_CLOSED,
                'purchase_order',
                $orderId,
                $before,
                array_merge($after, ['reason' => $reason]),
                $branchId,
            );

            $this->events->lifecycle($connection, OrderEvents::CLOSED, $after);

            return new ClosePurchaseOrderResult($orderId, true, $after);
        });
    }
}
