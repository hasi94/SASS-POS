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
 * **What is NOT proved here, and must not be claimed:** AC-37's
 * concurrency half. Two removals racing, each seeing the other as the
 * remaining owner, is `[ENGINE]` and ships NOT VERIFIED - the guard is a
 * read followed by a write, and only a real engine with real locking can
 * say whether both can win. Nothing in this class or its tests should be
 * read as evidence either way.
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
        if (! $this->isActingOwner($connection, $employeeId)) {
            // Not an owner, or already not acting: the change cannot remove
            // the last one, and refusing here would block a repair.
            return;
        }

        if ($this->actingOwnersOtherThan($connection, $employeeId) > 0) {
            return;
        }

        throw AccessRefused::lastOwner(
            "Employee [{$employeeId}] is the only acting owner of this tenant."
        );
    }

    /**
     * Is this employee an acting owner right now?
     */
    public function isActingOwner(Connection $connection, string $employeeId): bool
    {
        return $this->ownerQuery($connection)
            ->where('employees.id', $employeeId)
            ->exists();
    }

    /**
     * How many acting owners there are apart from this one.
     */
    private function actingOwnersOtherThan(Connection $connection, string $employeeId): int
    {
        return $this->ownerQuery($connection)
            ->where('employees.id', '!=', $employeeId)
            ->count();
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
