<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Features\SubmitPurchaseOrder;

use Flexipos\Contracts\Access\PermissionResolver;
use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Contracts\Workflow\WorkflowContext;
use Flexipos\Contracts\Workflow\WorkflowEntityType;
use Flexipos\Contracts\Workflow\WorkflowEvaluationFailed;
use Flexipos\Contracts\Workflow\WorkflowGate;
use Flexipos\Contracts\Workflow\WorkflowOutcome;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Purchasing\Documents\DocumentLocks;
use Flexipos\Purchasing\Documents\OrderEvents;
use Flexipos\Purchasing\Documents\OrderImage;
use Flexipos\Purchasing\Documents\OrderStatus;
use Flexipos\Purchasing\Documents\Places;
use Flexipos\Purchasing\Exceptions\PurchasingRefusal;
use Flexipos\Purchasing\Exceptions\PurchasingRefused;
use Flexipos\Purchasing\Registry\PurchasingAuditActions;
use Flexipos\Purchasing\Registry\PurchasingPermissions;
use Flexipos\Purchasing\Support\Authorise;
use Flexipos\Purchasing\Support\Identifier;
use Flexipos\Purchasing\Support\Lookup;
use Flexipos\Purchasing\Support\TenantConnection;

/**
 * Submit a draft order for approval (T-034 AC-54 to AC-58, AC-97, D-11; Rulings
 * 5, 6, 7, 11).
 *
 * **Fail closed.** `WorkflowGate::evaluate()` is called once, **inside** the submit
 * transaction, after the header lock and the supplier's share lock. Any
 * `WorkflowEvaluationFailed` is caught only to be rethrown as `workflow_failed`
 * (the engine's exception as `previous`, its string as `detail`), which rolls the
 * transaction back: the order stays `draft`, no instance id is stored, nothing is
 * audited and no event is written. The catch never continues.
 *
 * What is judged first, under the header lock, in this order: a `received`,
 * `closed` or `cancelled` order is `order_closed` (AC-66); any other status that
 * cannot move to `pending_approval` is `invalid_transition`; no lines is
 * `no_lines`; a supplier that is now inactive or soft-deleted is `supplier_inactive`;
 * a location that is no longer an active delivery location of the order's branch is
 * `unknown_location` (AC-54). **Nothing is re-quoted** and no stored figure is
 * altered.
 *
 * - `NotRequired` approves at once: `approved`, one `purchasing_order_approved` row
 *   saying approval was not required, and the `purchase_order.approved` event.
 * - `Pending` stores the instance id and moves to `pending_approval`, one
 *   `purchasing_order_submitted` row and no event; the order is then locked (AC-60).
 *
 * The amount handed to the engine is the stored header `total`, as the string it is
 * stored as (D-20); the requester is the submitting actor (AC-55). **Idempotent by
 * outcome:** an order already `pending_approval` or `approved` is returned as it is,
 * the engine is not asked again and no second instance is opened (AC-57).
 */
final class SubmitPurchaseOrderHandler
{
    public const NOT_REQUIRED_NOTE = 'approval not required';

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly AuditTrail $audit,
        private readonly PermissionResolver $permissions,
        private readonly WorkflowGate $workflow,
        private readonly Places $places,
        private readonly OrderEvents $events,
    ) {}

    public function handle(SubmitPurchaseOrder $command): SubmitPurchaseOrderResult
    {
        $orderId = Identifier::fold($command->orderId, 'order_id');

        $connection = $this->tenant->get();

        $header = Lookup::any($connection, 'purchase_orders', $orderId, 'purchase order');
        $branchId = strtoupper((string) $header->branch_id);

        $actorId = Authorise::require($this->permissions, $command->actorId, PurchasingPermissions::ORDERS_MANAGE, $branchId);

        return $connection->transaction(function () use ($connection, $orderId, $branchId, $actorId): SubmitPurchaseOrderResult {
            $before = OrderImage::header(DocumentLocks::order($connection, $orderId));
            $status = OrderStatus::from($before['status']);

            if ($status === OrderStatus::PendingApproval || $status === OrderStatus::Approved) {
                return new SubmitPurchaseOrderResult($orderId, $status->value, $before['workflow_instance_id'], false, $before);
            }

            if ($status->isEditClosed()) {
                throw PurchasingRefused::because(PurchasingRefusal::OrderClosed, "Purchase order [{$before['number']}] is [{$status->value}] and cannot be submitted.");
            }

            $status->assertCanMoveTo(OrderStatus::PendingApproval);

            $lineCount = $connection->table('purchase_order_items')->where('order_id', $orderId)->count();

            if ($lineCount === 0) {
                throw PurchasingRefused::because(PurchasingRefusal::NoLines, "Purchase order [{$before['number']}] has no lines.");
            }

            $supplier = Lookup::any($connection, 'suppliers', $before['supplier_id'], 'supplier', Lookup::SHARED);

            if ($supplier->deleted_at !== null || ! (bool) $supplier->is_active) {
                throw PurchasingRefused::because(PurchasingRefusal::SupplierInactive, "Supplier [{$before['supplier_id']}] is inactive or deleted.");
            }

            $this->places->location($connection, $before['location_id'], $branchId);

            try {
                $decision = $this->workflow->evaluate(
                    WorkflowEntityType::PurchaseOrder,
                    new WorkflowContext($orderId, $actorId, $branchId, $before['total'], [
                        'supplier_id' => $before['supplier_id'],
                        'location_id' => $before['location_id'],
                        'currency' => $before['currency'],
                        'line_count' => $lineCount,
                    ]),
                );
            } catch (WorkflowEvaluationFailed $failure) {
                throw PurchasingRefused::fromWorkflow($failure);
            }

            if ($decision->outcome === WorkflowOutcome::NotRequired) {
                $to = OrderStatus::Approved;
                $instanceId = null;
                $action = PurchasingAuditActions::ORDER_APPROVED;
                $note = ['note' => self::NOT_REQUIRED_NOTE];
            } else {
                if ($decision->instanceId === null) {
                    throw PurchasingRefused::because(PurchasingRefusal::WorkflowFailed, 'The workflow engine asked for approval and named no instance.');
                }

                $to = OrderStatus::PendingApproval;
                $instanceId = strtoupper($decision->instanceId);
                $action = PurchasingAuditActions::ORDER_SUBMITTED;
                $note = [];
            }

            $connection->table('purchase_orders')->where('id', $orderId)->update([
                'status' => $to->value,
                'workflow_instance_id' => $instanceId,
                'updated_at' => $this->clock->timestamp(),
                'updated_by' => $actorId,
            ]);

            $after = OrderImage::header($connection->table('purchase_orders')->where('id', $orderId)->first()
                ?? throw PurchasingRefused::notFound("No purchase order has id [{$orderId}]."));

            $this->audit->record(
                PurchasingAuditActions::MODULE,
                $actorId,
                $action,
                'purchase_order',
                $orderId,
                $before,
                array_merge($after, $note),
                $branchId,
            );

            if ($to === OrderStatus::Approved) {
                $this->events->lifecycle($connection, OrderEvents::APPROVED, $after);
            }

            return new SubmitPurchaseOrderResult($orderId, $to->value, $instanceId, true, $after);
        });
    }
}
