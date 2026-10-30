<?php

declare(strict_types=1);

namespace Flexipos\Access\Support;

use Flexipos\Contracts\Access\RoleHolders;

/**
 * {@see RoleHolders} over the tenant database (T-020 Ruling 6a).
 *
 * "Acting" reads {@see EmployeeStatus} - the one authority for "which
 * `employees.status` values may act" - rather than a copy of its set, and
 * `deleted_at` is checked beside it: a suspended or soft-deleted employee
 * is never assigned a workflow task.
 */
final class DatabaseRoleHolders implements RoleHolders
{
    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly EmployeeStatus $status,
    ) {}

    public function holdersOf(array $roleIds, ?string $branchId): array
    {
        if ($roleIds === []) {
            return [];
        }

        $query = $this->tenant->get()->table('employee_roles')
            ->join('employees', 'employees.id', '=', 'employee_roles.employee_id')
            ->whereIn('employee_roles.role_id', $roleIds)
            ->whereNull('employees.deleted_at')
            ->whereIn('employees.status', $this->status->acting());

        // Branch narrowing (Ruling 6a): an all-branch assignment always
        // counts; a branch-specific one counts only for its own branch;
        // with no branch on the request, only all-branch holders count.
        if ($branchId === null) {
            $query->whereNull('employee_roles.branch_id');
        } else {
            $query->where(function ($inner) use ($branchId) {
                $inner->whereNull('employee_roles.branch_id')
                    ->orWhere('employee_roles.branch_id', $branchId);
            });
        }

        $rows = $query
            ->select(['employees.id as employee_id', 'employee_roles.role_id as role_id'])
            ->get();

        $byEmployee = [];

        foreach ($rows as $row) {
            $employeeId = (string) $row->employee_id;
            $roleId = (string) $row->role_id;

            if (! isset($byEmployee[$employeeId]) || strcmp($roleId, $byEmployee[$employeeId]) < 0) {
                $byEmployee[$employeeId] = $roleId;
            }
        }

        ksort($byEmployee);

        return $byEmployee;
    }

    public function actingAmong(array $employeeIds): array
    {
        if ($employeeIds === []) {
            return [];
        }

        $ids = $this->tenant->get()->table('employees')
            ->whereIn('id', $employeeIds)
            ->whereNull('deleted_at')
            ->whereIn('status', $this->status->acting())
            ->orderBy('id')
            ->pluck('id')
            ->all();

        return array_map(static fn (mixed $id): string => (string) $id, $ids);
    }
}
