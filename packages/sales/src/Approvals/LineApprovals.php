<?php

declare(strict_types=1);

namespace Flexipos\Sales\Approvals;

use Flexipos\Sales\Exceptions\SalesRefused;
use Flexipos\Sales\Support\TenantConnection;

/**
 * Which approval covers a price override or an approval-gated discount on a
 * sale line (T-030 AC-7, AC-23a, AC-55; D-37, D-47, D-59).
 *
 * **A new approval is consumed through {@see ApprovalGate}**, bound to the
 * sale's own branch, `reference_type = sale` and the sale, with the payload the
 * line is about to carry. The one exception is a re-price (AC-55): when an
 * approval **already consumed by this sale** carries exactly this payload, it
 * still covers the line, so a changed quantity on an overridden line needs no
 * second approval. A different price, unit or discount has a different payload
 * and needs a new one.
 *
 * Called inside the writing transaction, after the sale lock (Ruling 7: the
 * approval row is lock 3).
 */
final class LineApprovals
{
    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly ApprovalGate $gate,
    ) {}

    /**
     * @param  array{product_id: string, unit_id: string, unit_price: string}  $payload
     *
     * @throws SalesRefused approval_required, approval_not_approved, approval_already_used, approval_mismatch
     */
    public function coverOverride(object $sale, array $payload, ?string $approvalId): ConsumedApproval
    {
        return $this->cover(ApprovalType::PriceOverride, $sale, $payload, $approvalId);
    }

    /**
     * @throws SalesRefused approval_required, approval_not_approved, approval_already_used, approval_mismatch
     */
    public function coverDiscount(object $sale, string $discountId, ?string $approvalId): ConsumedApproval
    {
        return $this->cover(ApprovalType::Discount, $sale, ['discount_id' => $discountId], $approvalId);
    }

    /**
     * @param  array<string, string>  $payload
     */
    private function cover(ApprovalType $type, object $sale, array $payload, ?string $approvalId): ConsumedApproval
    {
        $saleId = strtoupper((string) $sale->id);
        $branchId = strtoupper((string) $sale->branch_id);

        if ($approvalId === null) {
            $prior = $this->priorConsumed($type, $saleId, $branchId, $payload);

            if ($prior !== null) {
                return $prior;
            }
        }

        return $this->gate->consume($type, $branchId, ApprovalBinding::SALE, $saleId, $payload, $approvalId);
    }

    /**
     * The approval this sale already used for exactly this payload, if any. Read
     * by the sale's id and compared in PHP, never through a `WHERE` on a payload
     * or on a column a case-insensitive collation could fold (R-38).
     *
     * @param  array<string, string>  $payload
     */
    private function priorConsumed(ApprovalType $type, string $saleId, string $branchId, array $payload): ?ConsumedApproval
    {
        $rows = $this->tenant->get()->table('approval_requests')
            ->where('reference_type', ApprovalBinding::SALE)
            ->where('reference_id', $saleId)
            ->where('type', $type->value)
            ->where('status', ApprovalStatus::Approved->value)
            ->whereNotNull('consumed_at')
            ->get();

        foreach ($rows as $row) {
            $stored = $row->payload === null ? null : json_decode((string) $row->payload, true);

            if (strtoupper((string) $row->branch_id) === $branchId && ApprovalBinding::payloadMatches(is_array($stored) ? $stored : null, $payload)) {
                return new ConsumedApproval(strtoupper((string) $row->id), strtoupper((string) $row->decided_by), strtoupper((string) $row->requested_by));
            }
        }

        return null;
    }
}
