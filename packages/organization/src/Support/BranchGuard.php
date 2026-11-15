<?php

declare(strict_types=1);

namespace Flexipos\Organization\Support;

use Flexipos\Organization\Exceptions\OrganizationRefused;
use Illuminate\Database\Connection;

/**
 * "A branch this operation may attach something to" (T-014 AC-15, AC-21,
 * AC-26).
 *
 * Creating a location under a branch, creating a terminal at one and (step 14)
 * writing a setting scoped to one all ask the same question, so the answer
 * lives once: the id is folded, the branch must exist (`unknown_branch`) and
 * must be active (`branch_inactive`). Reading, detaching and clearing never
 * call it - an inactive branch's rows stay readable and its rights revocable
 * (D-6).
 */
final class BranchGuard
{
    /**
     * @throws OrganizationRefused validation (malformed id), unknown_branch, branch_inactive
     */
    public static function requireActive(Connection $connection, mixed $branchId, bool $lock = false): object
    {
        $id = Identifier::fold($branchId, 'branch id');

        $query = $connection->table('branches')->where('id', $id)->whereNull('deleted_at');

        if ($lock) {
            $query->lockForUpdate();
        }

        $row = $query->first();

        if ($row === null) {
            throw OrganizationRefused::unknownBranch("No branch has id [{$id}].");
        }

        if (! (bool) $row->is_active) {
            throw OrganizationRefused::branchInactive("Branch [{$id}] is not active.");
        }

        return $row;
    }
}
