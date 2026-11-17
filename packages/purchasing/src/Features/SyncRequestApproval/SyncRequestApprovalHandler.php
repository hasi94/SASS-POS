<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Features\SyncRequestApproval;

use Flexipos\Contracts\Access\PermissionResolver;
use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Contracts\Workflow\WorkflowActionRefused;
use Flexipos\Contracts\Workflow\WorkflowEntityType;
use Flexipos\Contracts\Workflow\WorkflowGate;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Purchasing\Documents\ApprovalOutcome;
use Flexipos\Purchasing\Documents\DocumentLocks;
use Flexipos\Purchasing\Documents\RequestImage;
use Flexipos\Purchasing\Documents\RequestStatus;
use Flexipos\Purchasing\Exceptions\PurchasingRefusal;
use Flexipos\Purchasing\Exceptions\PurchasingRefused;
use Flexipos\Purchasing\Registry\PurchasingAuditActions;
use Flexipos\Purchasing\Registry\PurchasingPermissions;
use Flexipos\Purchasing\Support\Authorise;
use Flexipos\Purchasing\Support\Identifier;
use Flexipos\Purchasing\Support\Lookup;
use Flexipos\Purchasing\Support\TenantConnection;

/**
 * Pull the workflow engine's decision into a purchase request (T-034 AC-28, AC-38,
 * D-11, D-50, D-52; Ruling 6).
 *
 * **Nothing calls this slice.** The engine does not notify the module that owns the
 * document, so whoever calls the workflow's `decide()` must call this too, or a
 * decided approval does not move the request. That gap is the owner-visible
 * follow-up of D-52 and is not built here. **It must run after `decide()` has
 * committed**: the instance is read under the header lock, so a call made inside
 * the deciding transaction, or before it commits, sees `pending` and changes
 * nothing.
 *
 * It acts only on a `pending_approval` request; anything else is returned
 * unchanged (`changed = false`, no audit row), which is also what makes a second
 * sync a no-op. The instance is read under the header lock, and its entity type and
 * id must be this request's, else `invalid_transition`: an instance id that points
 * at someone else's approval is never applied.
 *
 * The outcome ({@see ApprovalOutcome::forRequest()}): approved is `approved`;
 * rejected, for either `on_reject`, is `rejected`; cancelled or expired is `draft`
 * with the instance id cleared; still pending changes nothing. The permission is
 * the request's `view` key at its branch: a sync applies a decision the engine
 * already made and confers nothing.
 */
final class SyncRequestApprovalHandler
{
    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly AuditTrail $audit,
        private readonly PermissionResolver $permissions,
        private readonly WorkflowGate $workflow,
    ) {}

    public function handle(SyncRequestApproval $command): SyncRequestApprovalResult
    {
        $requestId = Identifier::fold($command->requestId, 'request_id');

        $connection = $this->tenant->get();

        $header = Lookup::any($connection, 'purchase_requests', $requestId, 'purchase request');
        $branchId = strtoupper((string) $header->branch_id);

        $actorId = Authorise::require($this->permissions, $command->actorId, PurchasingPermissions::REQUESTS_VIEW, $branchId);

        return $connection->transaction(function () use ($connection, $requestId, $branchId, $actorId): SyncRequestApprovalResult {
            $before = RequestImage::header(DocumentLocks::request($connection, $requestId));
            $status = RequestStatus::from($before['status']);

            if ($status !== RequestStatus::PendingApproval) {
                return new SyncRequestApprovalResult($requestId, $status->value, false, $before);
            }

            $instanceId = $before['workflow_instance_id']
                ?? throw PurchasingRefused::because(PurchasingRefusal::InvalidTransition, "Purchase request [{$before['number']}] is pending approval and holds no workflow instance.");

            try {
                $state = $this->workflow->instance($instanceId);
            } catch (WorkflowActionRefused $refused) {
                throw PurchasingRefused::fromWorkflow($refused);
            }

            if ($state->entityType !== WorkflowEntityType::PurchaseRequest->value || strtoupper($state->entityId) !== $requestId) {
                throw PurchasingRefused::because(
                    PurchasingRefusal::InvalidTransition,
                    "Workflow instance [{$instanceId}] belongs to another document and is not applied to purchase request [{$before['number']}].",
                );
            }

            $outcome = ApprovalOutcome::forRequest($state->status, $state->onReject);

            if (! $outcome->to instanceof RequestStatus || $outcome->action === null) {
                return new SyncRequestApprovalResult($requestId, $status->value, false, $before);
            }

            $status->assertCanMoveTo($outcome->to);

            $connection->table('purchase_requests')->where('id', $requestId)->update([
                'status' => $outcome->to->value,
                'workflow_instance_id' => $outcome->clearsInstance ? null : $instanceId,
                'updated_at' => $this->clock->timestamp(),
                'updated_by' => $actorId,
            ]);

            $after = RequestImage::header($connection->table('purchase_requests')->where('id', $requestId)->first()
                ?? throw PurchasingRefused::notFound("No purchase request has id [{$requestId}]."));

            $this->audit->record(
                PurchasingAuditActions::MODULE,
                $actorId,
                $outcome->action,
                'purchase_request',
                $requestId,
                $before,
                array_merge($after, ['workflow_status' => $state->status->value]),
                $branchId,
            );

            return new SyncRequestApprovalResult($requestId, $outcome->to->value, true, $after);
        });
    }
}
