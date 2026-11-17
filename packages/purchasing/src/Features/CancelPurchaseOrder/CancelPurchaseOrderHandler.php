<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Features\CancelPurchaseOrder;

use Flexipos\Contracts\Access\PermissionResolver;
use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Contracts\Workflow\WorkflowActionRefused;
use Flexipos\Contracts\Workflow\WorkflowGate;
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
 * Cancel an order (T-034 AC-64, AC-67, AC-97; Rulings 4, 6, 11).
 *
 * From `draft`, `pending_approval` and `approved` with nothing received. Judged
 * under the header lock, in this order: a `cancelled` order is a **no-op success**
 * (`changed = false`, nothing written); `partially_received` and `received` are
 * `already_received` (close it instead); `closed` and `sent` are
 * `invalid_transition`; an order with any received base or **any receipt-application
 * row, even one reversed to zero** is `already_received` (D-45). A `reason` of 1 to
 * 500 characters is **required** to cancel an `approved` order and optional
 * otherwise; one that is present is always checked.
 *
 * A `pending_approval` order's instance is cancelled through
 * `WorkflowGate::cancel()` after the header lock; if the engine says the instance is
 * already closed (decided, not yet synced) the cancel still proceeds and the audit
 * row records the status the instance had; any other engine refusal is
 * `workflow_failed` and rolls everything back. The `purchase_order.cancelled` event
 * is written **only** for an order that had been `approved`. The row is never
 * deleted and its number is never reused.
 */
final class CancelPurchaseOrderHandler
{
    private const FIELDS = ['order_id', 'reason'];

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly AuditTrail $audit,
        private readonly PermissionResolver $permissions,
        private readonly WorkflowGate $workflow,
        private readonly OrderEvents $events,
    ) {}

    public function handle(CancelPurchaseOrder $command): CancelPurchaseOrderResult
    {
        $fields = $command->fields;

        Fields::only($fields, self::FIELDS);

        $orderId = Identifier::fold($fields['order_id'] ?? null, 'order_id');

        $connection = $this->tenant->get();

        $header = Lookup::any($connection, 'purchase_orders', $orderId, 'purchase order');
        $branchId = strtoupper((string) $header->branch_id);

        $actorId = Authorise::require($this->permissions, $command->actorId, PurchasingPermissions::ORDERS_MANAGE, $branchId);

        $reason = ReasonInput::optional($fields);

        return $connection->transaction(function () use ($connection, $orderId, $branchId, $actorId, $reason): CancelPurchaseOrderResult {
            $before = OrderImage::header(DocumentLocks::order($connection, $orderId));
            $status = OrderStatus::from($before['status']);

            if ($status === OrderStatus::Cancelled) {
                return new CancelPurchaseOrderResult($orderId, false);
            }

            if ($status === OrderStatus::PartiallyReceived || $status === OrderStatus::Received) {
                throw PurchasingRefused::because(PurchasingRefusal::AlreadyReceived, "Purchase order [{$before['number']}] has had goods received against it. Close it instead.");
            }

            $status->assertCanMoveTo(OrderStatus::Cancelled);

            OrderReceipts::assertNone($connection, $orderId, $before['number'], 'Close it instead.');

            if ($status === OrderStatus::Approved && $reason === null) {
                throw PurchasingRefused::validation('A reason of 1 to '.ReasonInput::MAX.' characters is required to cancel an approved order.');
            }

            $note = $reason === null ? [] : ['reason' => $reason];

            if ($status === OrderStatus::PendingApproval && $before['workflow_instance_id'] !== null) {
                $note['workflow_status'] = $this->cancelInstance($before['workflow_instance_id'], $actorId, $reason);
            }

            $connection->table('purchase_orders')->where('id', $orderId)->update([
                'status' => OrderStatus::Cancelled->value,
                'updated_at' => $this->clock->timestamp(),
                'updated_by' => $actorId,
            ]);

            $after = OrderImage::header($connection->table('purchase_orders')->where('id', $orderId)->first()
                ?? throw PurchasingRefused::notFound("No purchase order has id [{$orderId}]."));

            $this->audit->record(
                PurchasingAuditActions::MODULE,
                $actorId,
                PurchasingAuditActions::ORDER_CANCELLED,
                'purchase_order',
                $orderId,
                $before,
                array_merge($after, $note),
                $branchId,
            );

            if ($status === OrderStatus::Approved) {
                $this->events->lifecycle($connection, OrderEvents::CANCELLED, $after);
            }

            return new CancelPurchaseOrderResult($orderId, true);
        });
    }

    /**
     * Cancel the instance and say what it ended as: `cancelled`, or the status a
     * decided-but-unsynced instance already had.
     *
     * @throws PurchasingRefused workflow_failed
     */
    private function cancelInstance(string $instanceId, string $actorId, ?string $reason): string
    {
        try {
            return $this->workflow->cancel($instanceId, $actorId, $reason)->status->value;
        } catch (WorkflowActionRefused $refused) {
            if ($refused->reason !== 'instanceClosed') {
                throw PurchasingRefused::fromWorkflow($refused);
            }

            try {
                return $this->workflow->instance($instanceId)->status->value;
            } catch (WorkflowActionRefused $unreadable) {
                throw PurchasingRefused::fromWorkflow($unreadable);
            }
        }
    }
}
