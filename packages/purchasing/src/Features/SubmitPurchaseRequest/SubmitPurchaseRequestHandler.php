<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Features\SubmitPurchaseRequest;

use Flexipos\Contracts\Access\PermissionResolver;
use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Contracts\Workflow\WorkflowContext;
use Flexipos\Contracts\Workflow\WorkflowEntityType;
use Flexipos\Contracts\Workflow\WorkflowEvaluationFailed;
use Flexipos\Contracts\Workflow\WorkflowGate;
use Flexipos\Contracts\Workflow\WorkflowOutcome;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Purchasing\Documents\DocumentLocks;
use Flexipos\Purchasing\Documents\RequestAmount;
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
 * Submit a draft request for approval (T-034 AC-26, AC-27, AC-37, D-11; Rulings
 * 5, 6, 7).
 *
 * **Fail closed.** `WorkflowGate::evaluate()` is called once, **inside** the
 * submit transaction, after the header lock. Any `WorkflowEvaluationFailed` is
 * caught only to be rethrown as `workflow_failed` (the engine's exception as
 * `previous`, its string as `detail`), which rolls the transaction back: the
 * request stays `draft`, no instance id is stored, nothing is audited. The catch
 * never continues, so an engine failure is never read as "no approval needed".
 *
 * - `NotRequired` approves at once: `approved`, one `purchasing_request_approved`
 *   row whose after image says approval was not required.
 * - `Pending` stores the instance id and moves to `pending_approval`, one
 *   `purchasing_request_submitted` row; the request is then locked (AC-25).
 *
 * **The amount** is the sum of `quantity x estimated_cost` ({@see RequestAmount}),
 * checked to fit before the engine is asked; the context carries `location_id` and
 * `line_count`. The requester named to the engine is the request's own
 * `requested_by`, so the engine's no-self-approval rule applies to whoever raised
 * the request. A request with no lines is `no_lines` and the gate is not called.
 *
 * **Idempotent by outcome:** a request already `pending_approval` or `approved` is
 * returned as it is. Any other status is `invalid_transition`.
 */
final class SubmitPurchaseRequestHandler
{
    public const NOT_REQUIRED_NOTE = 'approval not required';

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly AuditTrail $audit,
        private readonly PermissionResolver $permissions,
        private readonly WorkflowGate $workflow,
    ) {}

    public function handle(SubmitPurchaseRequest $command): SubmitPurchaseRequestResult
    {
        $requestId = Identifier::fold($command->requestId, 'request_id');

        $connection = $this->tenant->get();

        $header = Lookup::any($connection, 'purchase_requests', $requestId, 'purchase request');
        $branchId = strtoupper((string) $header->branch_id);

        $actorId = Authorise::require($this->permissions, $command->actorId, PurchasingPermissions::REQUESTS_MANAGE, $branchId);

        return $connection->transaction(function () use ($connection, $requestId, $branchId, $actorId): SubmitPurchaseRequestResult {
            $locked = DocumentLocks::request($connection, $requestId);
            $before = RequestImage::header($locked);
            $status = RequestStatus::from($before['status']);

            if ($status === RequestStatus::PendingApproval || $status === RequestStatus::Approved) {
                return new SubmitPurchaseRequestResult($requestId, $status->value, $before['workflow_instance_id'], false, $before);
            }

            $status->assertCanMoveTo(RequestStatus::PendingApproval);

            $lines = array_map(
                static fn (object $row): array => RequestImage::line($row),
                $connection->table('purchase_request_items')->where('request_id', $requestId)->orderBy('line_no')->get()->all(),
            );

            if ($lines === []) {
                throw PurchasingRefused::because(PurchasingRefusal::NoLines, "Purchase request [{$before['number']}] has no lines.");
            }

            $amount = RequestAmount::of($lines);

            try {
                $decision = $this->workflow->evaluate(
                    WorkflowEntityType::PurchaseRequest,
                    new WorkflowContext($requestId, $before['requested_by'], $branchId, $amount, [
                        'location_id' => $before['location_id'],
                        'line_count' => count($lines),
                    ]),
                );
            } catch (WorkflowEvaluationFailed $failure) {
                throw PurchasingRefused::fromWorkflow($failure);
            }

            $now = $this->clock->timestamp();

            if ($decision->outcome === WorkflowOutcome::NotRequired) {
                $to = RequestStatus::Approved;
                $instanceId = null;
                $action = PurchasingAuditActions::REQUEST_APPROVED;
                $note = ['note' => self::NOT_REQUIRED_NOTE];
            } else {
                if ($decision->instanceId === null) {
                    throw PurchasingRefused::because(PurchasingRefusal::WorkflowFailed, 'The workflow engine asked for approval and named no instance.');
                }

                $to = RequestStatus::PendingApproval;
                $instanceId = strtoupper($decision->instanceId);
                $action = PurchasingAuditActions::REQUEST_SUBMITTED;
                $note = [];
            }

            $connection->table('purchase_requests')->where('id', $requestId)->update([
                'status' => $to->value,
                'workflow_instance_id' => $instanceId,
                'updated_at' => $now,
                'updated_by' => $actorId,
            ]);

            $after = RequestImage::header($connection->table('purchase_requests')->where('id', $requestId)->first()
                ?? throw PurchasingRefused::notFound("No purchase request has id [{$requestId}]."));

            $this->audit->record(
                PurchasingAuditActions::MODULE,
                $actorId,
                $action,
                'purchase_request',
                $requestId,
                $before,
                array_merge($after, $note),
                $branchId,
            );

            return new SubmitPurchaseRequestResult($requestId, $to->value, $instanceId, true, $after);
        });
    }
}
