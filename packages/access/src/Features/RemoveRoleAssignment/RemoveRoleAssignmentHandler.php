<?php

declare(strict_types=1);

namespace Flexipos\Access\Features\RemoveRoleAssignment;

use Flexipos\Access\Exceptions\AccessRefused;
use Flexipos\Access\Support\AuditWriter;
use Flexipos\Access\Support\BranchScope;
use Flexipos\Access\Support\EmployeeLookup;
use Flexipos\Access\Support\LastOwner;
use Flexipos\Access\Support\PermVersion;
use Flexipos\Access\Support\RoleLookup;
use Flexipos\Access\Support\SelfChange;
use Flexipos\Access\Support\TenantConnection;
use Illuminate\Database\Connection;

/**
 * Take one scoped role assignment away (AC-33, AC-52).
 *
 * **Removing a row that is not there is a refusal, not a silent success**
 * (AC-33). `delete()` returning zero is the shape where a caller is told
 * "done" for an operation that did nothing, and the caller then believes
 * access was withdrawn that was never withdrawn - which is the direction of
 * this mistake that matters.
 *
 * **And it bumps nothing.** The refusal is thrown inside the transaction,
 * before the bump, so a reader asserting "`perm_version` did not move" is
 * asserting the state the whole transaction left behind rather than the
 * order of two statements.
 *
 * **It deletes at exactly one scope**, through {@see BranchScope::at()}.
 * Dropping the scope clause would delete the role at every branch when one
 * was asked for, and the caller would be told it succeeded - which is why
 * this file's fixture gives the employee the same role at two scopes and
 * asserts the other one survived.
 *
 * **AC-37 is asked only when the grant being removed is an owner's**, and
 * the condition is the role's `is_system` flag at the null scope. Removing
 * a non-owner role from the last owner is not the thing AC-37 protects,
 * and a guard that refused it would block an ordinary tidy-up with a
 * refusal nobody could act on. What an acting owner *is* stays in
 * {@see LastOwner}; when to ask is the only part this handler knows.
 *
 * **The guard runs before the delete, inside the transaction.** Asking
 * afterwards - "is there still an owner?" - would read the state this
 * statement created and would be correct too, but it would have to undo
 * the delete to refuse, and an operation that writes before deciding is
 * one rollback away from being wrong.
 */
final class RemoveRoleAssignmentHandler
{
    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly LastOwner $lastOwner,
        private readonly AuditWriter $audit,
    ) {}

    public function handle(RemoveRoleAssignment $command): void
    {
        $branchId = BranchScope::requireWellFormed($command->branchId);

        SelfChange::refuse($command->actorId, $command->employeeId, 'remove a role');

        $connection = $this->tenant->get();

        $connection->transaction(function () use ($connection, $command, $branchId): void {
            EmployeeLookup::findLive($connection, $command->employeeId);

            if ($branchId === null && $this->removesSystemRole($connection, $command->roleId)) {
                $this->lastOwner->requireAnother($connection, $command->employeeId);
            }

            $deleted = BranchScope::at(
                $connection->table('employee_roles')
                    ->where('employee_id', $command->employeeId)
                    ->where('role_id', $command->roleId),
                $branchId
            )->delete();

            if ($deleted === 0) {
                throw AccessRefused::validation(
                    "Employee [{$command->employeeId}] holds no role [{$command->roleId}] at this scope."
                );
            }

            PermVersion::bump($connection, [$command->employeeId]);

            $this->audit->write(
                $connection,
                $command->actorId,
                'role_assignment_removed',
                'employee',
                $command->employeeId,
                ['role_id' => $command->roleId, 'branch_id' => $branchId],
                null,
                $branchId,
            );
        });
    }

    /**
     * Is the role being removed one the platform owns?
     *
     * The flag on the row, never the key `owner` - the rule
     * {@see RoleLookup} states and the reason it states it. A role that no
     * longer exists is not a system role, and the removal then meets
     * AC-33's absent-row refusal a few lines later, which is the right
     * refusal for it.
     */
    private function removesSystemRole(Connection $connection, string $roleId): bool
    {
        return $connection->table('roles')
            ->where('id', $roleId)
            ->where('is_system', true)
            ->exists();
    }
}
