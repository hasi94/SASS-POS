<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Documents;

use Flexipos\Contracts\Organization\BranchDirectory;
use Flexipos\Contracts\Organization\BranchState;
use Flexipos\Purchasing\Exceptions\PurchasingRefusal;
use Flexipos\Purchasing\Exceptions\PurchasingRefused;
use Flexipos\Purchasing\Support\Identifier;
use Illuminate\Database\Connection;

/**
 * The branch and the delivery location a document names (T-034 AC-20, AC-41,
 * D-38, D-40).
 *
 * **The branch** is asked of `BranchDirectory`, the published contract: unknown
 * and inactive are both `unknown_branch` (AC-20), because nothing new may be
 * scoped to a branch that cannot trade. Only then is its row read for the two
 * facts the contract does not give: `timezone`, which dates the document, and
 * `currency`, the last step of the currency fallback (D-41). Read-only.
 *
 * **The location** is eligible iff it exists, is not soft-deleted, `is_active`,
 * `is_purchasable_to`, and its `branch_id` equals the document's branch (D-40). A
 * central warehouse (`branch_id` null) is therefore `unknown_location`, and so is
 * a location of another branch: the refusal does not say which part failed,
 * because the caller learns nothing it may act on from the difference.
 *
 * Both reads are plain, on autocommit, before the write transaction (Ruling 7).
 */
final class Places
{
    public function __construct(private readonly BranchDirectory $branches) {}

    /**
     * @return object{id: string, timezone: string, currency: string}
     *
     * @throws PurchasingRefused validation (malformed id), unknown_branch
     */
    public function branch(Connection $connection, mixed $branchId): object
    {
        $folded = Identifier::fold($branchId, 'branch_id');

        if ($this->branches->state($folded) !== BranchState::Active) {
            throw PurchasingRefused::because(PurchasingRefusal::UnknownBranch, "No active branch has id [{$folded}].");
        }

        $row = $connection->table('branches')->where('id', $folded)->first(['id', 'timezone', 'currency']);

        if ($row === null) {
            throw PurchasingRefused::because(PurchasingRefusal::UnknownBranch, "No active branch has id [{$folded}].");
        }

        return (object) [
            'id' => $folded,
            'timezone' => (string) $row->timezone,
            'currency' => trim((string) $row->currency),
        ];
    }

    /**
     * @return object{id: string, branch_id: string}
     *
     * @throws PurchasingRefused validation (malformed id), unknown_location
     */
    public function location(Connection $connection, mixed $locationId, string $branchId): object
    {
        $folded = Identifier::fold($locationId, 'location_id');

        $row = $connection->table('locations')
            ->where('id', $folded)
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->where('is_purchasable_to', true)
            ->where('branch_id', $branchId)
            ->first(['id', 'branch_id']);

        if ($row === null) {
            throw PurchasingRefused::because(
                PurchasingRefusal::UnknownLocation,
                "Location [{$folded}] is not an active delivery location of branch [{$branchId}].",
            );
        }

        return (object) ['id' => $folded, 'branch_id' => $branchId];
    }
}
