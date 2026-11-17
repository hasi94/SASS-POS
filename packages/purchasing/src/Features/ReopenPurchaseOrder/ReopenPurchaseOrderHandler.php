<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Features\ReopenPurchaseOrder;

use Flexipos\Contracts\Access\PermissionResolver;
use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Purchasing\Documents\DocumentLocks;
use Flexipos\Purchasing\Documents\OrderEvents;
use Flexipos\Purchasing\Documents\OrderImage;
use Flexipos\Purchasing\Documents\OrderReceipts;
use Flexipos\Purchasing\Documents\OrderStatus;
use Flexipos\Purchasing\Documents\ReasonInput;
use Flexipos\Purchasing\Exceptions\PurchasingRefusal;
use Flexipos\Purchasing\Exceptions\PurchasingRefused;
use Flexipos\Purchasing\Registry\PurchasingAuditActions;
use Flexipos\Purchasing\Registry\PurchasingPermissions;
use Flexipos\Purchasing\Support\Authorise;
use Flexipos\Purchasing\Support\Fields;
use Flexipos\Purchasing\Support\Identifier;
use Flexipos\Purchasing\Support\Lookup;
use Flexipos\Purchasing\Support\TenantConnection;

/**
 * Take an approved order back to `draft` (T-034 AC-62, AC-66, AC-67, D-45; Rulings
 * 4, 11).
 *
 * Needs `purchasing.orders.reopen` at the order's stored branch and a `reason` of 1
 * to 500 characters. Judged under the header lock, in this order:
 *
 * - `received`, `closed`, `cancelled`: `order_closed` (AC-66);
 * - `partially_received`: `already_received`;
 * - `draft`, `pending_approval`, `sent`: `invalid_transition`;
 * - `approved` with a received base on any line **or any receipt-application row,
 *   even one reversed to zero**: `already_received` (D-45); otherwise the order is
 *   `draft`, `workflow_instance_id` cleared, and re-submitting runs a new approval.
 *
 * One audit row `purchasing_order_reopened` carries the reason and the instance id
 * the order held; the `purchase_order.reopened` event is written in the same
 * transaction (AC-97). Not idempotent by outcome: a reopened order is a `draft`, and
 * reopening a draft is `invalid_transition`.
 */
final class ReopenPurchaseOrderHandler
{
    private const FIELDS = ['order_id', 'reason'];

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly AuditTrail $audit,
        private readonly PermissionResolver $permissions,
        private readonly OrderEvents $events,
    ) {}

    public function handle(ReopenPurchaseOrder $command): ReopenPurchaseOrderResult
    {
        $fields = $command->fields;

        Fields::only($fields, self::FIELDS);

        $orderId = Identifier::fold($fields['order_id'] ?? null, 'order_id');

        $connection = $this->tenant->get();

        $header = Lookup::any($connection, 'purchase_orders', $orderId, 'purchase order');
        $branchId = strtoupper((string) $header->branch_id);

        $actorId = Authorise::require($this->permissions, $command->actorId, PurchasingPermissions::ORDERS_REOPEN, $branchId);

        $reason = ReasonInput::required($fields);

        return $connection->transaction(function () use ($connection, $orderId, $branchId, $actorId, $reason): ReopenPurchaseOrderResult {
            $before = OrderImage::header(DocumentLocks::order($connection, $orderId));
            $status = OrderStatus::from($before['status']);

            if ($status->isEditClosed()) {
                throw PurchasingRefused::because(PurchasingRefusal::OrderClosed, "Purchase order [{$before['number']}] is [{$status->value}] and cannot be reopened.");
            }

            if ($status === OrderStatus::PartiallyReceived) {
                throw PurchasingRefused::because(PurchasingRefusal::AlreadyReceived, "Purchase order [{$before['number']}] has had goods received against it. Close it instead.");
            }

            if ($status !== OrderStatus::Approved) {
                throw PurchasingRefused::because(PurchasingRefusal::InvalidTransition, "A purchase order that is [{$status->value}] cannot be reopened.");
            }

            OrderReceipts::assertNone($connection, $orderId, $before['number'], 'It can no longer be reopened.');

            $status->assertCanMoveTo(OrderStatus::Draft);

            $connection->table('purchase_orders')->where('id', $orderId)->update([
                'status' => OrderStatus::Draft->value,
                'workflow_instance_id' => null,
                'updated_at' => $this->clock->timestamp(),
                'updated_by' => $actorId,
            ]);

            $after = OrderImage::header($connection->table('purchase_orders')->where('id', $orderId)->first()
                ?? throw PurchasingRefused::notFound("No purchase order has id [{$orderId}]."));

            $this->audit->record(
                PurchasingAuditActions::MODULE,
                $actorId,
                PurchasingAuditActions::ORDER_REOPENED,
                'purchase_order',
                $orderId,
                $before,
                array_merge($after, ['reason' => $reason, 'previous_workflow_instance_id' => $before['workflow_instance_id']]),
                $branchId,
            );

            $this->events->lifecycle($connection, OrderEvents::REOPENED, $after);

            return new ReopenPurchaseOrderResult($orderId, OrderStatus::Draft->value, $before['workflow_instance_id'], $after);
        });
    }
}
