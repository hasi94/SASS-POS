<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Features\CancelPurchaseRequest;

use Flexipos\Contracts\Access\PermissionResolver;
use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Contracts\Workflow\WorkflowActionRefused;
use Flexipos\Contracts\Workflow\WorkflowGate;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Purchasing\Documents\DocumentLocks;
use Flexipos\Purchasing\Documents\RequestImage;
use Flexipos\Purchasing\Documents\RequestStatus;
use Flexipos\Purchasing\Exceptions\PurchasingRefused;
use Flexipos\Purchasing\Registry\PurchasingAuditActions;
use Flexipos\Purchasing\Registry\PurchasingPermissions;
use Flexipos\Purchasing\Support\Authorise;
use Flexipos\Purchasing\Support\Identifier;
use Flexipos\Purchasing\Support\Lookup;
use Flexipos\Purchasing\Support\TenantConnection;

/**
 * Cancel a purchase request (T-034 AC-29, AC-37; Ruling 6).
 *
 * From `draft`, `pending_approval` and `approved`; a `rejected` or `converted`
 * request is `invalid_transition` (a converted request stays converted even when
 * its order is cancelled, D-15). **Idempotent by outcome:** cancelling a cancelled
 * request succeeds and writes nothing.
 *
 * A `pending_approval` request's instance is cancelled through
 * `WorkflowGate::cancel()`, inside the transaction after the header lock. If the
 * engine answers that the instance is already closed (it was decided and nobody
 * has synced it yet), the cancel still proceeds: the user's intent is that the
 * request ends, and the audit row records the status the instance had. Any other
 * engine refusal is `workflow_failed` and rolls everything back. The instance id
 * stays on the cancelled request as the trace of what happened.
 */
final class CancelPurchaseRequestHandler
{
    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly AuditTrail $audit,
        private readonly PermissionResolver $permissions,
        private readonly WorkflowGate $workflow,
    ) {}

    public function handle(CancelPurchaseRequest $command): CancelPurchaseRequestResult
    {
        $requestId = Identifier::fold($command->requestId, 'request_id');

        $connection = $this->tenant->get();

        $header = Lookup::any($connection, 'purchase_requests', $requestId, 'purchase request');
        $branchId = strtoupper((string) $header->branch_id);

        $actorId = Authorise::require($this->permissions, $command->actorId, PurchasingPermissions::REQUESTS_MANAGE, $branchId);

        return $connection->transaction(function () use ($connection, $requestId, $branchId, $actorId): CancelPurchaseRequestResult {
            $before = RequestImage::header(DocumentLocks::request($connection, $requestId));
            $status = RequestStatus::from($before['status']);

            if ($status === RequestStatus::Cancelled) {
                return new CancelPurchaseRequestResult($requestId, false);
            }

            $status->assertCanMoveTo(RequestStatus::Cancelled);

            $note = [];

            if ($status === RequestStatus::PendingApproval && $before['workflow_instance_id'] !== null) {
                $note = ['workflow_status' => $this->cancelInstance($before['workflow_instance_id'], $actorId)];
            }

            $connection->table('purchase_requests')->where('id', $requestId)->update([
                'status' => RequestStatus::Cancelled->value,
                'updated_at' => $this->clock->timestamp(),
                'updated_by' => $actorId,
            ]);

            $after = RequestImage::header($connection->table('purchase_requests')->where('id', $requestId)->first()
                ?? throw PurchasingRefused::notFound("No purchase request has id [{$requestId}]."));

            $this->audit->record(
                PurchasingAuditActions::MODULE,
                $actorId,
                PurchasingAuditActions::REQUEST_CANCELLED,
                'purchase_request',
                $requestId,
                $before,
                array_merge($after, $note),
                $branchId,
            );

            return new CancelPurchaseRequestResult($requestId, true);
        });
    }

    /**
     * Cancel the instance and say what it ended as: `cancelled`, or the status a
     * decided-but-unsynced instance already had.
     *
     * @throws PurchasingRefused workflow_failed
     */
    private function cancelInstance(string $instanceId, string $actorId): string
    {
        try {
            return $this->workflow->cancel($instanceId, $actorId)->status->value;
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
