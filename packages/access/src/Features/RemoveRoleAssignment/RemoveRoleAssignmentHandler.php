<?php

declare(strict_types=1);

namespace Flexipos\Access\Features\RemoveRoleAssignment;

use Flexipos\Access\Exceptions\AccessRefused;
use Flexipos\Access\Support\BranchScope;
use Flexipos\Access\Support\EmployeeLookup;
use Flexipos\Access\Support\PermVersion;
use Flexipos\Access\Support\TenantConnection;

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
 * AC-34's `self_change` and AC-37's `last_owner` both refuse this operation
 * and are step 14's; they are absent rather than stubbed.
 */
final class RemoveRoleAssignmentHandler
{
    public function __construct(private readonly TenantConnection $tenant) {}

    public function handle(RemoveRoleAssignment $command): void
    {
        $branchId = BranchScope::requireWellFormed($command->branchId);

        $connection = $this->tenant->get();

        $connection->transaction(function () use ($connection, $command, $branchId): void {
            EmployeeLookup::findLive($connection, $command->employeeId);

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
        });
    }
}
