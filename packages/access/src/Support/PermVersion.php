<?php

declare(strict_types=1);

namespace Flexipos\Access\Support;

use Illuminate\Database\Connection;

/**
 * The one place `employees.perm_version` is raised (D-28, AC-52).
 *
 * **Every bump is a single atomic statement, never read-then-write.** Two
 * qualifying changes landing at once must raise the version by two, and a
 * handler that read the version, added one and wrote it back would lose one
 * of them - and lose it silently, because both callers would see a version
 * that moved. The `[ENGINE]` half of AC-52 is two concurrent writers, which
 * cannot be run while the engine hold stands; what *can* be held to account
 * today is the shape, so the shape lives here where a reviewer reads it once
 * rather than in each of the dozen operations that bump.
 *
 * **The holders of a role are selected inside the statement, not before it.**
 * A role's permissions changing affects every employee holding it at any
 * scope, and reading that list first would leave a window in which a
 * newly-assigned holder is missed - and would be the mask shape where a
 * write re-filters on the terms its own read already applied.
 *
 * It takes the connection rather than resolving one: the caller is inside a
 * transaction on a particular connection, and a bump that landed on another
 * one would not roll back with the change it belongs to.
 */
final class PermVersion
{
    /**
     * Raise the version of every employee holding this role, at any scope.
     *
     * @return int how many employees were bumped
     */
    public static function bumpHoldersOfRole(Connection $connection, string $roleId): int
    {
        return $connection->table('employees')
            ->whereIn('id', static function ($query) use ($roleId): void {
                $query->select('employee_id')
                    ->from('employee_roles')
                    ->where('role_id', $roleId);
            })
            ->update(['perm_version' => $connection->raw('perm_version + 1')]);
    }

    /**
     * Raise the version of these employees.
     *
     * An empty list is a no-op rather than an error: "bump everyone affected"
     * with nobody affected is a legitimate outcome, and the alternative would
     * push an `if` into every caller.
     *
     * @param  list<string>  $employeeIds
     * @return int how many employees were bumped
     */
    public static function bump(Connection $connection, array $employeeIds): int
    {
        if ($employeeIds === []) {
            return 0;
        }

        return $connection->table('employees')
            ->whereIn('id', $employeeIds)
            ->update(['perm_version' => $connection->raw('perm_version + 1')]);
    }
}
