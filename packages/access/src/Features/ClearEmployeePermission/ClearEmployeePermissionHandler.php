<?php

declare(strict_types=1);

namespace Flexipos\Access\Features\ClearEmployeePermission;

use Flexipos\Access\Exceptions\AccessRefused;
use Flexipos\Access\Support\BranchScope;
use Flexipos\Access\Support\EmployeeLookup;
use Flexipos\Access\Support\PermVersion;
use Flexipos\Access\Support\TenantConnection;

/**
 * Remove one per-person allow or deny (AC-32's clearing half, AC-33).
 *
 * **Clearing is not denying.** Removing an allow leaves whatever the
 * employee's roles confer; removing a deny lets a role's grant through
 * again. Neither is "set it to false", which is why this is its own
 * operation rather than a flag on {@see
 * \Flexipos\Access\Features\SetEmployeePermission\SetEmployeePermission}.
 *
 * **A row that is not there is `validation` and bumps nothing** (AC-33),
 * for the reason {@see
 * \Flexipos\Access\Features\RemoveRoleAssignment\RemoveRoleAssignmentHandler}
 * gives: a caller told "done" believes a deny was lifted, or an allow
 * withdrawn, that is still in place.
 *
 * The key is not checked against `permissions` here, and that is not an
 * oversight: `employee_permissions.permission_key` is foreign-keyed to it,
 * so an unregistered key has no row to clear and meets the same refusal the
 * absent-row case does, with the same remedy.
 *
 * AC-34's `self_change` is step 14's and is absent rather than stubbed.
 */
final class ClearEmployeePermissionHandler
{
    public function __construct(private readonly TenantConnection $tenant) {}

    public function handle(ClearEmployeePermission $command): void
    {
        $key = trim($command->permissionKey);
        $branchId = BranchScope::requireWellFormed($command->branchId);

        $connection = $this->tenant->get();

        $connection->transaction(function () use ($connection, $command, $key, $branchId): void {
            EmployeeLookup::findLive($connection, $command->employeeId);

            $deleted = BranchScope::at(
                $connection->table('employee_permissions')
                    ->where('employee_id', $command->employeeId)
                    ->where('permission_key', $key),
                $branchId
            )->delete();

            if ($deleted === 0) {
                throw AccessRefused::validation(
                    "Employee [{$command->employeeId}] holds no personal row for [{$key}] at this scope."
                );
            }

            PermVersion::bump($connection, [$command->employeeId]);
        });
    }
}
