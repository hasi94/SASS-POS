<?php

declare(strict_types=1);

namespace Flexipos\Access\Support;

use Illuminate\Database\Connection;

/**
 * The branches an employee is explicitly attached to, as the `branch_ids`
 * claim defines them (D-2, AC-57).
 *
 * **One home, because two readers answer the same question.** The claim
 * builder mints `branch_ids` into a token and "my permissions" reads it
 * live for a client that wants it without minting a new one (AC-60). Two
 * copies of this query could come to disagree about order, about
 * duplicates, or about whether an attachment the employee no longer has
 * still counts - and the client's whole use of the claim is to compare the
 * two.
 *
 * **Explicit attachments only, and that meaning is final** (D-2). It is
 * never expanded to "every branch" for somebody who holds an all-branches
 * grant: there is no `branches` table to expand from, and defining the
 * empty list as "all" would redefine the claim the day FP-209 creates one.
 * The all-branches case is carried by its own flag, which is why both
 * exist.
 */
final class EmployeeBranches
{
    /**
     * Distinct and sorted ascending, which is the order the claim's own
     * type requires - so the value is a set and not a history of
     * attachment.
     *
     * @return list<string>
     */
    public static function of(Connection $connection, string $employeeId): array
    {
        $ids = $connection->table('employee_branches')
            ->where('employee_id', $employeeId)
            ->orderBy('branch_id')
            ->pluck('branch_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->unique()
            ->values()
            ->all();

        /** @var list<string> $ids */
        return $ids;
    }
}
