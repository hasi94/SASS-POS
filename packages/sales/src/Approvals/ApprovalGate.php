<?php

declare(strict_types=1);

namespace Flexipos\Sales\Approvals;

use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\Sales\Exceptions\SalesRefusal;
use Flexipos\Sales\Exceptions\SalesRefused;
use Flexipos\Sales\Support\Identifier;
use Flexipos\Sales\Support\TenantConnection;

/**
 * The one place an approval is used up (T-030 Ruling 5, D-47, AC-24).
 *
 * **Called inside the consuming write's transaction**, after that write has
 * taken the locks that come before it in Ruling 7's order (the shift, the sale):
 * the approval row is lock 3. It locks the row `FOR UPDATE`, reads it **by id**
 * and compares in PHP, never through a `WHERE` on a column a case-insensitive
 * collation would fold (R-38). Refusals, in this order:
 *
 * | condition | refusal |
 * |---|---|
 * | no id given, or no such row | `approval_required` |
 * | `status` is not `approved` (still pending, rejected, expired) | `approval_not_approved` |
 * | `consumed_at` is already set | `approval_already_used` |
 * | type, branch, reference or payload differs from what the write is doing | `approval_mismatch` |
 *
 * Otherwise it sets `consumed_at` and tells the caller who approved it. A
 * refusal names nothing the row did not already carry: no reason, no payload
 * value. It does not write an audit row; the consuming slice's own row covers
 * the whole change, consumption included.
 */
final class ApprovalGate
{
    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
    ) {}

    /**
     * @param  array<string, mixed>|null  $payloadMatch  what the write is about to do, for the types that bind one
     *
     * @throws SalesRefused approval_required, approval_not_approved, approval_already_used, approval_mismatch
     */
    public function consume(
        ApprovalType $type,
        string $branchId,
        string $referenceType,
        ?string $referenceId,
        ?array $payloadMatch,
        ?string $approvalId,
    ): ConsumedApproval {
        if ($approvalId === null) {
            throw SalesRefused::because(SalesRefusal::ApprovalRequired, "A [{$type->value}] approval is required.");
        }

        $id = Identifier::fold($approvalId, 'approvalId');
        $connection = $this->tenant->get();

        $row = $connection->table('approval_requests')->where('id', $id)->lockForUpdate()->first();

        if ($row === null) {
            throw SalesRefused::because(SalesRefusal::ApprovalRequired, "No approval [{$id}] exists.");
        }

        if ((string) $row->status !== ApprovalStatus::Approved->value) {
            throw SalesRefused::because(SalesRefusal::ApprovalNotApproved, "Approval [{$id}] is [{$row->status}], not approved.");
        }

        if ($row->consumed_at !== null) {
            throw SalesRefused::because(SalesRefusal::ApprovalAlreadyUsed, "Approval [{$id}] has already been used.");
        }

        $stored = $row->payload === null ? null : json_decode((string) $row->payload, true);

        if ((string) $row->type !== $type->value
            || strtoupper((string) $row->branch_id) !== Identifier::fold($branchId, 'branchId')
            || (string) $row->reference_type !== $referenceType
            || ($row->reference_id === null ? null : strtoupper((string) $row->reference_id)) !== Identifier::foldNullable($referenceId, 'referenceId')
            || ! ApprovalBinding::payloadMatches(is_array($stored) ? $stored : null, $payloadMatch)) {
            throw SalesRefused::because(SalesRefusal::ApprovalMismatch, "Approval [{$id}] is not for this {$type->value}.");
        }

        $now = $this->clock->timestamp();

        $connection->table('approval_requests')->where('id', $id)->update(['consumed_at' => $now, 'updated_at' => $now]);

        return new ConsumedApproval($id, strtoupper((string) $row->decided_by), strtoupper((string) $row->requested_by));
    }
}
