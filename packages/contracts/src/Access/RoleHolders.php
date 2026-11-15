<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Access;

/**
 * Who holds a role, and who among a given set may currently act (T-020
 * Ruling 6a).
 *
 * `packages/workflow` never queries `employees`, `roles` or
 * `employee_roles` itself - the same `EmployeeDirectory` precedent T-014
 * follows. Implemented by `packages/access`.
 */
interface RoleHolders
{
    /**
     * Employees who hold one of the given roles, narrowed to `$branchId`
     * (T-020 Ruling 6a): an all-branch assignment always counts; a
     * branch-specific one counts only for its own branch; when `$branchId`
     * is null, only all-branch holders count.
     *
     * Only acting employees are returned (suspended and soft-deleted
     * employees never are).
     *
     * @param  list<string>  $roleIds
     * @return array<string, string> employee id => the smallest qualifying role id, ordered by employee id
     */
    public function holdersOf(array $roleIds, ?string $branchId): array;

    /**
     * The given employee ids, filtered to live, acting employees.
     *
     * @param  list<string>  $employeeIds
     * @return list<string>
     */
    public function actingAmong(array $employeeIds): array;
}
