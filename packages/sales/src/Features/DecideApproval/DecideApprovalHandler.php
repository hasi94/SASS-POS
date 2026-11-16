<?php

declare(strict_types=1);

namespace Flexipos\Sales\Features\DecideApproval;

use Flexipos\Contracts\Access\PermissionResolver;
use Flexipos\Contracts\Access\PinVerifier;
use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Sales\Approvals\ApprovalBinding;
use Flexipos\Sales\Approvals\ApprovalStatus;
use Flexipos\Sales\Exceptions\SalesRefusal;
use Flexipos\Sales\Exceptions\SalesRefused;
use Flexipos\Sales\Registry\SalesAuditActions;
use Flexipos\Sales\Registry\SalesPermissions;
use Flexipos\Sales\Support\Fields;
use Flexipos\Sales\Support\Identifier;
use Flexipos\Sales\Support\TenantConnection;

/**
 * Decide an approval request (T-030 AC-23, AC-24; D-12 to D-15, D-30; Ruling 5).
 *
 * **The order is fixed, and the first step is outside any transaction.**
 *
 * 1. Validate the submission without touching the database. A malformed id is
 *    refused here, before the PIN is checked, so it costs nobody a counted
 *    attempt.
 * 2. `PinVerifier::verify()`. A null answer (a wrong PIN, an unknown code, a
 *    locked employee: indistinguishable to this caller) is `pin_invalid`, and
 *    nothing in the sales tables was written. Access writes its own `access_log`
 *    row and bumps its own counter in its own transaction, and that must survive
 *    whatever this feature refuses afterwards. Called inside a transaction that
 *    later rolled back, the increment would roll back with it, and the lockout
 *    would stop working the moment it matters most (mutation M3).
 * 3. One transaction. The approval row is locked first (it is the only row this
 *    write locks: lock 3, Ruling 7) and must be `pending`; the decider must not
 *    be the requester nor, for a sale-bound request, the sale's cashier
 *    (`self_approval_not_allowed`, compared as folded ids in PHP); and must hold
 *    `sales.approvals.decide` at the request's own branch (`not_authorised`).
 *    Only then is the row decided.
 *
 * A correct PIN proves who is deciding, not that they may. The PIN is never
 * stored or logged, and no message here repeats it or the code it came with.
 */
final class DecideApprovalHandler
{
    public const ACTION = 'sales_approval_decided';

    private const FIELDS = ['approval_id', 'employee_code', 'pin', 'decision', 'reason'];

    private const DECISIONS = ['approved', 'rejected'];

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly AuditTrail $audit,
        private readonly PinVerifier $pins,
        private readonly PermissionResolver $permissions,
    ) {}

    public function handle(DecideApproval $command): DecideApprovalResult
    {
        $fields = $command->fields;

        Fields::only($fields, self::FIELDS);

        $approvalId = Identifier::fold($fields['approval_id'] ?? null, 'approval_id');
        $code = Fields::requiredText($fields, 'employee_code', 50);
        $decision = Fields::enum($fields, 'decision', self::DECISIONS, null);
        $reason = Fields::optionalText($fields, 'reason', 255);
        $pin = $fields['pin'] ?? null;

        if ($decision === null) {
            throw SalesRefused::validation('The decision is required: approved or rejected.');
        }

        if (! is_string($pin) || $pin === '' || strlen($pin) > 64) {
            throw SalesRefused::validation('The pin is required.');
        }

        $deciderId = $this->pins->verify($code, $pin, $command->ip);

        if ($deciderId === null) {
            throw SalesRefused::because(SalesRefusal::PinInvalid, 'The code and PIN were not accepted.');
        }

        $deciderId = Identifier::fold($deciderId, 'decider');
        $connection = $this->tenant->get();

        return $connection->transaction(function () use ($connection, $approvalId, $deciderId, $decision, $reason): DecideApprovalResult {
            $before = $connection->table('approval_requests')->where('id', $approvalId)->lockForUpdate()->first();

            if ($before === null) {
                throw SalesRefused::notFound("No approval request has id [{$approvalId}].");
            }

            if ((string) $before->status !== ApprovalStatus::Pending->value) {
                throw SalesRefused::because(SalesRefusal::ApprovalNotPending, "Approval request [{$approvalId}] is already [{$before->status}].");
            }

            if (strtoupper((string) $before->requested_by) === $deciderId) {
                throw SalesRefused::because(SalesRefusal::SelfApprovalNotAllowed, 'A request cannot be decided by the employee who made it.');
            }

            if ((string) $before->reference_type === ApprovalBinding::SALE && $before->reference_id !== null) {
                $cashier = $connection->table('sales')->where('id', strtoupper((string) $before->reference_id))->value('employee_id');

                if ($cashier !== null && strtoupper((string) $cashier) === $deciderId) {
                    throw SalesRefused::because(SalesRefusal::SelfApprovalNotAllowed, "A sale's own cashier cannot decide an approval for it.");
                }
            }

            $branchId = strtoupper((string) $before->branch_id);

            if (! $this->permissions->decide($deciderId, SalesPermissions::APPROVALS_DECIDE, $branchId)->allowed) {
                throw SalesRefused::because(SalesRefusal::NotAuthorised, 'The employee may not decide approvals at this branch.');
            }

            $now = $this->clock->timestamp();

            $connection->table('approval_requests')->where('id', $approvalId)->update([
                'status' => $decision,
                'decided_by' => $deciderId,
                'decided_at' => $now,
                'decision_method' => 'pin_local',
                'reason' => $reason,
                'updated_at' => $now,
            ]);

            $after = $connection->table('approval_requests')->where('id', $approvalId)->first();

            $this->audit->record(SalesAuditActions::MODULE, $deciderId, self::ACTION, 'approval_request', $approvalId, (array) $before, $after === null ? null : (array) $after, $branchId);

            return new DecideApprovalResult($approvalId, $decision, $deciderId);
        });
    }
}
