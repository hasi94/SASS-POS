<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Documents;

use Flexipos\Contracts\Workflow\WorkflowInstanceStatus;
use Flexipos\Contracts\Workflow\WorkflowOnReject;
use Flexipos\Purchasing\Registry\PurchasingAuditActions;

/**
 * What a workflow instance's state means for the document that owns it (T-034
 * Ruling 4, AC-28, AC-59, D-50, Q-B1): the status to move to, whether the
 * stored `workflow_instance_id` is cleared, and the audit action.
 *
 * Pure: the `Sync*` slices read the instance, ask this for the outcome and
 * apply it. `status` is null when nothing moves (the instance is still
 * `pending`), and then there is no audit row either.
 *
 * - Requests: approved -> `approved`; rejected, for **either** `on_reject`
 *   -> `rejected`; cancelled or expired -> back to `draft`, instance cleared.
 * - Orders: approved -> `approved`; rejected with `return_to_requester` -> `draft`,
 *   cleared; rejected with `stop` -> `cancelled` (instance kept: the order was
 *   never approved, so no cancel event is written, AC-59); cancelled or expired
 *   -> `draft`, cleared.
 */
final readonly class ApprovalOutcome
{
    private function __construct(
        public RequestStatus|OrderStatus|null $to,
        public bool $clearsInstance,
        public ?string $action,
        public WorkflowInstanceStatus $workflowStatus,
    ) {}

    public static function forRequest(WorkflowInstanceStatus $status, WorkflowOnReject $onReject): self
    {
        return match ($status) {
            WorkflowInstanceStatus::Approved => new self(RequestStatus::Approved, false, PurchasingAuditActions::REQUEST_APPROVED, $status),
            WorkflowInstanceStatus::Rejected => new self(RequestStatus::Rejected, false, PurchasingAuditActions::REQUEST_REJECTED, $status),
            WorkflowInstanceStatus::Cancelled, WorkflowInstanceStatus::Expired => new self(RequestStatus::Draft, true, PurchasingAuditActions::REQUEST_RETURNED_TO_DRAFT, $status),
            WorkflowInstanceStatus::Pending => new self(null, false, null, $status),
        };
    }

    public static function forOrder(WorkflowInstanceStatus $status, WorkflowOnReject $onReject): self
    {
        return match ($status) {
            WorkflowInstanceStatus::Approved => new self(OrderStatus::Approved, false, PurchasingAuditActions::ORDER_APPROVED, $status),
            WorkflowInstanceStatus::Rejected => $onReject === WorkflowOnReject::ReturnToRequester
                ? new self(OrderStatus::Draft, true, PurchasingAuditActions::ORDER_RETURNED_TO_DRAFT, $status)
                : new self(OrderStatus::Cancelled, false, PurchasingAuditActions::ORDER_CANCELLED, $status),
            WorkflowInstanceStatus::Cancelled, WorkflowInstanceStatus::Expired => new self(OrderStatus::Draft, true, PurchasingAuditActions::ORDER_RETURNED_TO_DRAFT, $status),
            WorkflowInstanceStatus::Pending => new self(null, false, null, $status),
        };
    }

    /**
     * Whether the document moves at all.
     */
    public function changes(): bool
    {
        return $this->to !== null;
    }
}
