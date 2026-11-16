<?php

declare(strict_types=1);

namespace Flexipos\Sales\Approvals;

use Flexipos\Sales\Exceptions\SalesRefused;
use Flexipos\Sales\Support\Money;
use Flexipos\Sales\Support\TenantConnection;

/**
 * Which approval covers a price override or an approval-gated discount on a
 * sale line (T-030 AC-7, AC-23a, AC-55; D-37, D-47, D-59).
 *
 * **A new approval is consumed through {@see ApprovalGate}**, bound to the
 * sale's own branch, `reference_type = sale` and the sale, with the payload the
 * line is about to carry. The one exception is a re-price (AC-55): when an
 * approval **already consumed by this sale** carries exactly this payload, it
 * still covers **that same line**, so a changed quantity on an overridden line
 * needs no second approval. The path is open only to a re-price of an existing
 * line (`$line` given) that already carries exactly the covered override price or
 * discount; a new line (`AddSaleLine`) and a line that does not yet carry it always
 * need their own approval (BUG-1). A different price, unit or discount has a
 * different payload and needs a new one.
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
    public function coverOverride(object $sale, array $payload, ?string $approvalId, ?object $line = null): ConsumedApproval
    {
        $carried = $line !== null
            && $line->price_overridden_by !== null
            && strtoupper((string) $line->product_id) === strtoupper($payload['product_id'])
            && strtoupper((string) $line->unit_id) === strtoupper($payload['unit_id'])
            && Money::equals(Money::of($line->unit_price), Money::of($payload['unit_price']));

        return $this->cover(ApprovalType::PriceOverride, $sale, $payload, $approvalId, $carried, $carried ? strtoupper((string) $line->price_overridden_by) : null);
    }

    /**
     * @throws SalesRefused approval_required, approval_not_approved, approval_already_used, approval_mismatch
     */
    public function coverDiscount(object $sale, string $discountId, ?string $approvalId, ?object $line = null): ConsumedApproval
    {
        $carried = $line !== null
            && $line->discount_id !== null
            && strtoupper((string) $line->discount_id) === strtoupper($discountId);

        return $this->cover(ApprovalType::Discount, $sale, ['discount_id' => $discountId], $approvalId, $carried);
    }

    /**
     * @param  array<string, string>  $payload
     */
    private function cover(ApprovalType $type, object $sale, array $payload, ?string $approvalId, bool $lineCarriesIt, ?string $preferredDecider = null): ConsumedApproval
    {
        $saleId = strtoupper((string) $sale->id);
        $branchId = strtoupper((string) $sale->branch_id);

        if ($approvalId === null && $lineCarriesIt) {
            $prior = $this->priorConsumed($type, $saleId, $branchId, $payload, $preferredDecider);

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
     * **Which one, when two match (W-4):** two lines can carry the same payload yet be
     * approved by two managers. The approval whose decider is the line's own
     * `price_overridden_by` wins; failing that, the lowest id, never an unordered first row.
     *
     * @param  array<string, string>  $payload
     */
    private function priorConsumed(ApprovalType $type, string $saleId, string $branchId, array $payload, ?string $preferredDecider = null): ?ConsumedApproval
    {
        $rows = $this->tenant->get()->table('approval_requests')
            ->where('reference_type', ApprovalBinding::SALE)
            ->where('reference_id', $saleId)
            ->where('type', $type->value)
            ->where('status', ApprovalStatus::Approved->value)
            ->whereNotNull('consumed_at')
            ->orderBy('id')
            ->get();

        $found = null;

        foreach ($rows as $row) {
            $stored = $row->payload === null ? null : json_decode((string) $row->payload, true);

            if (strtoupper((string) $row->branch_id) === $branchId && ApprovalBinding::payloadMatches(is_array($stored) ? $stored : null, $payload)) {
                $candidate = new ConsumedApproval(strtoupper((string) $row->id), strtoupper((string) $row->decided_by), strtoupper((string) $row->requested_by));

                if ($preferredDecider === null || $candidate->decidedBy === $preferredDecider) {
                    return $candidate;
                }

                $found ??= $candidate;
            }
        }

        return $found;
    }
}
