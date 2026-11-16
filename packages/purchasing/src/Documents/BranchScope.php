<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Documents;

use Flexipos\Contracts\Access\PermissionResolver;
use Flexipos\Contracts\Access\RefusalReason;
use Flexipos\Purchasing\Exceptions\PurchasingRefused;
use Flexipos\Purchasing\Support\Authorise;
use Flexipos\Purchasing\Support\Identifier;
use Illuminate\Database\Connection;

/**
 * The branches a list may show (T-034 AC-35, AC-82, Ruling 9).
 *
 * A branch-scoped key is a question about one branch, so a list asks it per
 * branch:
 *
 * - **With a `branch_id` filter** the permission is decided once, at that branch,
 *   and a refusal is the slice's `not_authorised`: asking for a branch you cannot
 *   see is an error, not an empty answer.
 * - **Without one** the permission is decided for **every** `branches` row,
 *   inactive and soft-deleted ones included (a document outlives its branch), and
 *   the list is limited to the allowed set. **None allowed is an empty page**, not
 *   an error (Ruling 9), except for an employee who cannot act at all (suspended,
 *   inactive, deleted), who is refused with the resolver's reason (AC-86): that
 *   answer does not depend on a branch, so the first branch decision that says it
 *   is the answer. (A branch-scoped key asked with no branch is `branch_required`
 *   before availability is looked at, so there is no branch-free way to ask.)
 *
 * This costs one permission decision per branch (plan risk 7). Reads only.
 */
final class BranchScope
{
    /**
     * @return list<string> the folded ids of the branches the actor may list; empty means nothing
     *
     * @throws PurchasingRefused validation, not_authorised
     */
    public static function allowed(Connection $connection, PermissionResolver $permissions, ?string $actorId, string $key, mixed $branchFilter): array
    {
        if ($branchFilter !== null) {
            $branchId = Identifier::fold($branchFilter, 'branch_id');

            Authorise::require($permissions, $actorId, $key, $branchId);

            return [$branchId];
        }

        if ($actorId === null) {
            throw PurchasingRefused::validation('A list is read by an employee.');
        }

        $actor = Identifier::fold($actorId, 'actor');

        $allowed = [];

        foreach ($connection->table('branches')->orderBy('id')->pluck('id') as $branchId) {
            $id = strtoupper((string) $branchId);

            $decision = $permissions->decide($actor, $key, $id);

            if ($decision->allowed) {
                $allowed[] = $id;
            } elseif ($decision->reason === RefusalReason::EmployeeUnavailable) {
                // Whether the employee can act at all does not depend on the branch: the first answer is the answer.
                throw PurchasingRefused::fromAccess($decision, 'The employee cannot act.');
            }
        }

        return $allowed;
    }
}
