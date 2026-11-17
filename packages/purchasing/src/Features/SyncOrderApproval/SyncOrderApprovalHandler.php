<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Features\SyncOrderApproval;

use Flexipos\Contracts\Access\PermissionResolver;
use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Contracts\Workflow\WorkflowActionRefused;
use Flexipos\Contracts\Workflow\WorkflowEntityType;
use Flexipos\Contracts\Workflow\WorkflowGate;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Purchasing\Documents\ApprovalOutcome;
use Flexipos\Purchasing\Documents\DocumentLocks;
use Flexipos\Purchasing\Documents\OrderEvents;
use Flexipos\Purchasing\Documents\OrderImage;
use Flexipos\Purchasing\Documents\OrderStatus;
use Flexipos\Purchasing\Exceptions\PurchasingRefusal;
use Flexipos\Purchasing\Exceptions\PurchasingRefused;
use Flexipos\Purchasing\Registry\PurchasingAuditActions;
use Flexipos\Purchasing\Registry\PurchasingPermissions;
use Flexipos\Purchasing\Support\Authorise;
use Flexipos\Purchasing\Support\Identifier;
use Flexipos\Purchasing\Support\Lookup;
use Flexipos\Purchasing\Support\TenantConnection;

/**
 * Pull the workflow engine's decision into a purchase order (T-034 AC-59, AC-97,
 * D-50, D-52; Ruling 6).
 *
 * **Nothing calls this slice.** The engine does not notify the module that owns the
 * document, so whoever calls the workflow's `decide()` must call this too, or a
 * decided approval does not move the order (the owner-visible follow-up of D-52).
 * **It must run after `decide()` has committed**: the instance is read under the
 * header lock, so a call made inside the deciding transaction, or before it
 * commits, sees `pending` and changes nothing.
 *
 * It acts only on a `pending_approval` order; anything else is returned unchanged
 * (`changed = false`, no audit row, no event), which is also what makes a second
 * sync a no-op. The instance is read under the header lock and its entity type and
 * id must be this order's, else `invalid_transition`.
 *
 * The outcome ({@see ApprovalOutcome::forOrder()}): approved is `approved` and
 * writes the `purchase_order.approved` event; rejected with `return_to_requester`,
 * and cancelled or expired, are `draft` with the instance id cleared
 * (`purchasing_order_returned_to_draft`); rejected with `stop` is `cancelled`
 * (`purchasing_order_cancelled`, the workflow status in the after image) and writes
 * **no** cancel event, because the order was never approved (AC-59, Q-B1); still
 * pending changes nothing. The permission is the order's `view` key at its branch:
 * a sync applies a decision the engine already made and confers nothing.
 */
final class SyncOrderApprovalHandler
{
    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly AuditTrail $audit,
        private readonly PermissionResolver $permissions,
        private readonly WorkflowGate $workflow,
        private readonly OrderEvents $events,
    ) {}

    public function handle(SyncOrderApproval $command): SyncOrderApprovalResult
    {
        $orderId = Identifier::fold($command->orderId, 'order_id');

        $connection = $this->tenant->get();

        $header = Lookup::any($connection, 'purchase_orders', $orderId, 'purchase order');
        $branchId = strtoupper((string) $header->branch_id);

        $actorId = Authorise::require($this->permissions, $command->actorId, PurchasingPermissions::ORDERS_VIEW, $branchId);

        return $connection->transaction(function () use ($connection, $orderId, $branchId, $actorId): SyncOrderApprovalResult {
            $before = OrderImage::header(DocumentLocks::order($connection, $orderId));
            $status = OrderStatus::from($before['status']);

            if ($status !== OrderStatus::PendingApproval) {
                return new SyncOrderApprovalResult($orderId, $status->value, false, $before);
            }

            $instanceId = $before['workflow_instance_id']
                ?? throw PurchasingRefused::because(PurchasingRefusal::InvalidTransition, "Purchase order [{$before['number']}] is pending approval and holds no workflow instance.");

            try {
                $state = $this->workflow->instance($instanceId);
            } catch (WorkflowActionRefused $refused) {
                throw PurchasingRefused::fromWorkflow($refused);
            }

            if ($state->entityType !== WorkflowEntityType::PurchaseOrder->value || strtoupper($state->entityId) !== $orderId) {
                throw PurchasingRefused::because(
                    PurchasingRefusal::InvalidTransition,
                    "Workflow instance [{$instanceId}] belongs to another document and is not applied to purchase order [{$before['number']}].",
                );
            }

            $outcome = ApprovalOutcome::forOrder($state->status, $state->onReject);

            if (! $outcome->to instanceof OrderStatus || $outcome->action === null) {
                return new SyncOrderApprovalResult($orderId, $status->value, false, $before);
            }

            $status->assertCanMoveTo($outcome->to);

            $connection->table('purchase_orders')->where('id', $orderId)->update([
                'status' => $outcome->to->value,
                'workflow_instance_id' => $outcome->clearsInstance ? null : $instanceId,
                'updated_at' => $this->clock->timestamp(),
                'updated_by' => $actorId,
            ]);

            $after = OrderImage::header($connection->table('purchase_orders')->where('id', $orderId)->first()
                ?? throw PurchasingRefused::notFound("No purchase order has id [{$orderId}]."));

            $this->audit->record(
                PurchasingAuditActions::MODULE,
                $actorId,
                $outcome->action,
                'purchase_order',
                $orderId,
                $before,
                array_merge($after, ['workflow_status' => $state->status->value]),
                $branchId,
            );

            if ($outcome->to === OrderStatus::Approved) {
                $this->events->lifecycle($connection, OrderEvents::APPROVED, $after);
            }

            return new SyncOrderApprovalResult($orderId, $outcome->to->value, true, $after);
        });
    }
}
