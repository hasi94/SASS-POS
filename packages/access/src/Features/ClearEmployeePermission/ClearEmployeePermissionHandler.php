<?php

declare(strict_types=1);

namespace Flexipos\Access\Features\ClearEmployeePermission;

use Flexipos\Access\Exceptions\AccessRefused;
use Flexipos\Access\Support\AuditWriter;
use Flexipos\Access\Support\BranchScope;
use Flexipos\Access\Support\EmployeeLookup;
use Flexipos\Access\Support\Escalation;
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
 * **AC-34 applies to every clear; AC-36 applies to clearing a deny (D-37).**
 * Clearing a deny lets a role's grant through again, so it is a grant in
 * effect, and the actor clearing it is not the actor who was checked when
 * the role was assigned - a supervisor refused an allow of K could
 * otherwise reach the same result by deleting the deny. So the row is read
 * first, and a deny is asked exactly the question an allow is asked in
 * `SetEmployeePermission`: does the actor hold this key at this scope. A
 * null actor is `actor_required` (D-36). The question is asked before anything is
 * written, so a refusal leaves the row, the version and the audit log as
 * they were. Clearing an **allow** is not asked: removing a right raises
 * nobody.
 */
final class ClearEmployeePermissionHandler
{
    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly Escalation $escalation,
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

            $allowed = BranchScope::at(
                $connection->table('employee_permissions')
                    ->where('employee_id', $command->employeeId)
                    ->where('permission_key', $key),
                $branchId
            )->value('allowed');

            if ($allowed !== null && ! (bool) $allowed) {
                // D-37: lifting a deny restores the right, so it is bounded
                // as granting it would be.
                $this->escalation->requireHeld($command->actorId, $key, $branchId);
            }

            // Delete only the row that was judged (review W-6). The read
            // above is a plain read, so another administrator may swap an
            // allow for a deny before this runs; matching the flag judged
            // makes that row delete nothing and meet the refusal below,
            // rather than lifting a deny nobody checked. No row judged means
            // nothing is deleted, not whatever row has appeared since.
            $deleted = $allowed === null ? 0 : BranchScope::at(
                $connection->table('employee_permissions')
                    ->where('employee_id', $command->employeeId)
                    ->where('permission_key', $key)
                    ->where('allowed', (bool) $allowed),
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
