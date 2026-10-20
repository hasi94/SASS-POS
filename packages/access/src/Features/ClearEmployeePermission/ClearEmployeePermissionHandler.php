<?php

declare(strict_types=1);

namespace Flexipos\Access\Features\ClearEmployeePermission;

use Flexipos\Access\Exceptions\AccessRefused;
use Flexipos\Access\Support\AuditWriter;
use Flexipos\Access\Support\BranchScope;
use Flexipos\Access\Support\EmployeeLookup;
use Flexipos\Access\Support\PermVersion;
use Flexipos\Access\Support\SelfChange;
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
 * **AC-34 applies here and AC-36 does not.** Clearing takes a row away;
 * nobody ends up holding anything the actor does not, so there is nothing
 * to escalate. Clearing a **deny** does let a role's grant through again,
 * which looks like a grant - but the right it restores is one the role
 * already conferred, and the actor who may assign that role is the one
 * AC-36 checked when it was assigned.
 */
final class ClearEmployeePermissionHandler
{
    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly AuditWriter $audit,
    ) {}

    public function handle(ClearEmployeePermission $command): void
    {
        $key = trim($command->permissionKey);
        $branchId = BranchScope::requireWellFormed($command->branchId);

        SelfChange::refuse($command->actorId, $command->employeeId, 'clear a permission');

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

            $this->audit->write(
                $connection,
                $command->actorId,
                'employee_permission_cleared',
                'employee',
                $command->employeeId,
                ['permission_key' => $key, 'branch_id' => $branchId],
                null,
                $branchId,
            );
        });
    }
}
