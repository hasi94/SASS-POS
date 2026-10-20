<?php

declare(strict_types=1);

namespace Flexipos\Access\Support;

use Flexipos\Access\Exceptions\AccessRefused;
use Illuminate\Database\Connection;

/**
 * Finding an employee, and rendering one, in the one place both are decided.
 *
 * **"Live" means the same thing to every operation.** Six slices ask for an
 * employee by id, and a soft-deleted one must be absent to all of them -
 * refused `validation`, for {@see RoleLookup}'s reason: the closed refusal
 * set (D-26) has no `not_found`, and the request did name something that is
 * not there. An operation that read `employees` directly would be the one
 * that eventually forgets `whereNull('deleted_at')`, and a soft delete that
 * half worked is worse than one that did not.
 *
 * **Rendering is here too, because the view is built from two tables.** An
 * employee's roles come from `employee_roles` joined to `roles`, and the
 * list's version of that read must produce exactly what the detail's does
 * (AC-29). Two copies of the join is how one of them comes to forget that a
 * soft-deleted role is not a role.
 */
final class EmployeeLookup
{
    /**
     * The live employee with this id.
     *
     * @return object{id: string, code: string, first_name: string, last_name: ?string, display_name: string, status: string, pin_hash: ?string, pin_set_at: ?string, pin_failed_attempts: int, pin_locked_until: ?string}
     *
     * @throws AccessRefused when no live employee has that id
     */
    public static function findLive(Connection $connection, string $employeeId): object
    {
        $employee = $connection->table('employees')
            ->where('id', $employeeId)
            ->whereNull('deleted_at')
            ->first();

        if ($employee === null) {
            throw AccessRefused::validation("No live employee has id [{$employeeId}].");
        }

        /** @var object{id: string, code: string, first_name: string, last_name: ?string, display_name: string, status: string, pin_hash: ?string, pin_set_at: ?string, pin_failed_attempts: int, pin_locked_until: ?string} $employee */
        return $employee;
    }

    /**
     * Render these employee rows, reading every one's roles in one pass.
     *
     * @param  iterable<object>  $rows
     * @return list<EmployeeView>
     */
    public static function render(Connection $connection, iterable $rows): array
    {
        $ids = [];
        $ordered = [];

        foreach ($rows as $row) {
            /** @var object{id: string} $row */
            $ids[] = (string) $row->id;
            $ordered[] = $row;
        }

        $rolesByEmployee = self::rolesFor($connection, $ids);

        $views = [];

        foreach ($ordered as $row) {
            /** @var object{id: string, code: string, first_name: string, last_name: ?string, display_name: string, status: string, pin_hash: ?string} $row */
            $views[] = new EmployeeView(
                (string) $row->id,
                (string) $row->code,
                (string) $row->first_name,
                $row->last_name === null ? null : (string) $row->last_name,
                (string) $row->display_name,
                (string) $row->status,
                // The only thing about the PIN that leaves the database.
                $row->pin_hash !== null,
                $rolesByEmployee[(string) $row->id] ?? [],
            );
        }

        return $views;
    }

    /**
     * The live roles these employees hold, with their scopes, by employee id.
     *
     * A soft-deleted role contributes nothing, exactly as it contributes
     * nothing to a resolver answer: a list showing a role the role list
     * does not show would be two different answers to "which roles exist".
     *
     * @param  list<string>  $employeeIds
     * @return array<string, list<AssignedRole>>
     */
    private static function rolesFor(Connection $connection, array $employeeIds): array
    {
        if ($employeeIds === []) {
            return [];
        }

        $rows = $connection->table('employee_roles')
            ->join('roles', 'roles.id', '=', 'employee_roles.role_id')
            ->whereIn('employee_roles.employee_id', $employeeIds)
            ->whereNull('roles.deleted_at')
            ->orderBy('roles.key')
            ->get(['employee_roles.employee_id as employee_id', 'roles.key as role_key', 'employee_roles.branch_id as branch_id']);

        $byEmployee = [];

        foreach ($rows as $row) {
            /** @var object{employee_id: string, role_key: string, branch_id: ?string} $row */
            $byEmployee[(string) $row->employee_id][] = new AssignedRole(
                (string) $row->role_key,
                $row->branch_id === null ? null : (string) $row->branch_id,
            );
        }

        return $byEmployee;
    }
}
