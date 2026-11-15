<?php

declare(strict_types=1);

namespace Flexipos\Access\Support;

use Flexipos\Access\Exceptions\AccessRefused;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;

/**
 * A tenant never loses its last acting owner (AC-37).
 *
 * **Three operations can produce the outcome and none of them counts for
 * itself:** removing the owner assignment, moving the owner out of the
 * acting set (AC-27), and soft-deleting them (AC-28). What "an acting
 * owner" means has to be one definition - a non-deleted employee in the
 * acting set holding a **system** role at the null scope - because three
 * copies would come to disagree and the disagreement is only visible after
 * the shop has locked itself out.
 *
 * **It reads `roles.is_system`, never the key `owner`.** A shop naming its
 * own custom role `owner` must not thereby satisfy this guard, and a
 * platform role renamed must not escape it. That is the same rule
 * {@see RoleLookup::requireCustom()} follows, for the same reason.
 *
 * **Only the null scope counts.** An owner assignment scoped to one branch
 * does not administer the shop, so somebody holding it is not the person
 * this guard exists to keep.
 *
 * **The read takes row locks, and that is AC-37's concurrency half.** The
 * guard is a read followed, in the caller's transaction, by a write. Two
 * removals of two different owners each reading without a lock would each
 * see the other as the remaining owner and both commit. So the one read
 * this guard makes is `SELECT ... FOR UPDATE` over every acting owner,
 * **including this one**: both transactions run the same locking read in
 * the same order, the second blocks on the first row until the first
 * commits, then reads the committed state and sees one owner. Excluding
 * the employee being removed from the locked set would let the two lock
 * disjoint rows and meet in a deadlock instead of a refusal.
 *
 * **It must be the first locking statement in the caller's transaction.**
 * All three callers ask before they write anything. A future caller that
 * wrote first would hold a lock of its own when it got here, and two such
 * transactions could deadlock: still safe - the engine rolls one back with
 * error 1213 and no owner is lost - but surfaced as a server error rather
 * than `last_owner`.
 *
 * **What is NOT proved here, and must not be claimed:** that the engine
 * honours it. Laravel's SQLite grammar compiles no lock clause, so on the
 * stand-in the read is unchanged and the tests pin only that the statement
 * carries `for update` when compiled for MySQL. Two transactions racing is
 * `[ENGINE]` and ships NOT VERIFIED.
 */
final class LastOwner
{
    public function __construct(private readonly EmployeeStatus $statuses) {}

    /**
     * Refuse when this employee is the only acting owner left.
     *
     * The caller decides **when** to ask - removing a non-owner role from
     * the last owner is fine, and so is moving them from `active` to
     * `probation` - because only the caller knows what its change does.
     * What an owner is, this class decides.
     *
     * @throws AccessRefused last_owner
     */
    public function requireAnother(Connection $connection, string $employeeId): void
    {
        $owners = $this->lockedActingOwners($connection);

        if (! in_array($employeeId, $owners, true)) {
            // Not an owner, or already not acting: the change cannot remove
            // the last one, and refusing here would block a repair.
            return;
        }

        if (count($owners) > 1) {
            return;
        }

        throw AccessRefused::lastOwner(
            "Employee [{$employeeId}] is the only acting owner of this tenant."
        );
    }

    /**
     * Every acting owner's id, distinct, read under row locks.
     *
     * Distinct because one employee may hold two system roles at the null
     * scope and is still one owner. The lock covers every row the join
     * reads, so the employee, the assignment and the role a concurrent
     * removal would write are all held until this transaction ends.
     *
     * @return list<string>
     */
    private function lockedActingOwners(Connection $connection): array
    {
        $ids = [];

        foreach ($this->ownerQuery($connection)->lockForUpdate()->pluck('employees.id') as $id) {
            $ids[] = (string) $id;
        }

        return array_values(array_unique($ids));
    }

    /**
     * Non-deleted employees in the acting set holding a system role at the
     * null scope.
     *
     * The acting set comes from {@see EmployeeStatus} (D-15) and is never
     * restated: AC-27's moved-value proof relies on there being one
     * authority, and a guard carrying `['active', 'probation']` of its own
     * would be a second one.
     */
    private function ownerQuery(Connection $connection): Builder
    {
        return $connection->table('employees')
            ->join('employee_roles', 'employee_roles.employee_id', '=', 'employees.id')
            ->join('roles', 'roles.id', '=', 'employee_roles.role_id')
            ->whereNull('employees.deleted_at')
            ->whereNull('roles.deleted_at')
            ->whereNull('employee_roles.branch_id')
            ->where('roles.is_system', true)
            ->whereIn('employees.status', $this->statuses->acting());
    }
}
