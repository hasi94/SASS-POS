<?php

declare(strict_types=1);

namespace Flexipos\Access\Features\AssignRole;

use Flexipos\Access\Exceptions\AccessRefused;
use Flexipos\Access\Support\BranchScope;
use Flexipos\Access\Support\EmployeeLookup;
use Flexipos\Access\Support\PermVersion;
use Flexipos\Access\Support\RoleLookup;
use Flexipos\Access\Support\TenantConnection;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Support\Str;

/**
 * Give an employee a role at one scope (AC-30, AC-31, AC-52).
 *
 * **A system role is assignable and a soft-deleted one is not.** AC-21's
 * three protected operations are rename, re-permission and delete;
 * assignment is not one of them, and it must not be - `owner` is a system
 * role and AC-37's own control is "assign a second owner". So this handler
 * calls {@see RoleLookup::findLive()} and deliberately **not**
 * `requireCustom()`.
 *
 * **Branch existence is not validated, and that is the decision rather than
 * an omission** (D-30, AC-31). There is no `branches` table to check
 * against, so a well-formed id naming no branch is **accepted**; what is
 * checked is the shape of the id and whether the employee is attached
 * (D-11). Both live in {@see BranchScope}, so the four scoped operations of
 * this section cannot come to disagree, and the existence check has one
 * obvious home the day FP-209 ships `branches`.
 *
 * **The duplicate check reads the same rows the unique index covers.**
 * `(employee_id, role_id, branch_id_key)` is unique over the stored
 * sentinel, so the database already refuses the second assignment; the
 * check here turns that into a `validation` refusal with a message. It
 * narrows with {@see BranchScope::at()} so that the scope is part of what
 * is looked for: a check that dropped the scope would refuse the second
 * scope of a role as a duplicate of the first.
 *
 * AC-34's `self_change`, AC-36's `no_escalation` and AC-37's `last_owner`
 * are step 14's and are deliberately absent here rather than stubbed: a
 * guard written to permit is worse than one not yet written, because it
 * reads as present.
 */
final class AssignRoleHandler
{
    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
    ) {}

    public function handle(AssignRole $command): void
    {
        $branchId = BranchScope::requireWellFormed($command->branchId);

        $connection = $this->tenant->get();

        $connection->transaction(function () use ($connection, $command, $branchId): void {
            EmployeeLookup::findLive($connection, $command->employeeId);
            RoleLookup::findLive($connection, $command->roleId);

            BranchScope::requireAttached($connection, $command->employeeId, $branchId);

            $existing = BranchScope::at(
                $connection->table('employee_roles')
                    ->where('employee_id', $command->employeeId)
                    ->where('role_id', $command->roleId),
                $branchId
            )->exists();

            if ($existing) {
                throw AccessRefused::validation(
                    "Employee [{$command->employeeId}] already holds role [{$command->roleId}] at this scope."
                );
            }

            $connection->table('employee_roles')->insert([
                'id' => (string) Str::ulid(),
                'employee_id' => $command->employeeId,
                'role_id' => $command->roleId,
                'branch_id' => $branchId,
                'granted_by' => $command->actorId,
                'granted_at' => $this->clock->timestamp(),
            ]);

            PermVersion::bump($connection, [$command->employeeId]);
        });
    }
}
