<?php

declare(strict_types=1);

namespace Flexipos\Access\Features\DetachBranch;

use Flexipos\Access\Exceptions\AccessRefused;
use Flexipos\Access\Support\BranchScope;
use Flexipos\Access\Support\EmployeeLookup;
use Flexipos\Access\Support\PermVersion;
use Flexipos\Access\Support\TenantConnection;

/**
 * Detach an employee from a branch, unless grants still name it (AC-35,
 * D-11).
 *
 * **The refusal is `has_scoped_grants` and not `validation`, because the
 * remedy is a different operation**: remove the scoped grants first. It is
 * decided in {@see BranchScope::requireNoScopedGrants()}, which also owns
 * the list of tables a grant can live in.
 *
 * **Why the detach is refused rather than cascading.** Dropping the
 * attachment and leaving the grants would leave rows the resolver still
 * answers for at a scope the token's `branch_ids` no longer carries;
 * deleting the grants with it would be a silent withdrawal of access that
 * nobody asked for, hidden inside an operation whose name says nothing
 * about permissions. The refusal makes the caller say which they meant.
 *
 * **Detaching the default leaves the employee with no default** (AC-35),
 * and no replacement is chosen for them: picking one would be inventing a
 * business rule, and the generated-column index makes "no default" a legal
 * state while "two defaults" is not.
 */
final class DetachBranchHandler
{
    public function __construct(private readonly TenantConnection $tenant) {}

    public function handle(DetachBranch $command): void
    {
        $branchId = BranchScope::requireBranch($command->branchId);

        $connection = $this->tenant->get();

        $connection->transaction(function () use ($connection, $command, $branchId): void {
            EmployeeLookup::findLive($connection, $command->employeeId);

            $attached = $connection->table('employee_branches')
                ->where('employee_id', $command->employeeId)
                ->where('branch_id', $branchId)
                ->exists();

            if (! $attached) {
                throw AccessRefused::validation(
                    "Employee [{$command->employeeId}] is not attached to branch [{$branchId}]."
                );
            }

            BranchScope::requireNoScopedGrants($connection, $command->employeeId, $branchId);

            $connection->table('employee_branches')
                ->where('employee_id', $command->employeeId)
                ->where('branch_id', $branchId)
                ->delete();

            PermVersion::bump($connection, [$command->employeeId]);
        });
    }
}
