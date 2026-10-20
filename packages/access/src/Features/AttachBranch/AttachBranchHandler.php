<?php

declare(strict_types=1);

namespace Flexipos\Access\Features\AttachBranch;

use Flexipos\Access\Exceptions\AccessRefused;
use Flexipos\Access\Support\AuditWriter;
use Flexipos\Access\Support\BranchScope;
use Flexipos\Access\Support\EmployeeLookup;
use Flexipos\Access\Support\PermVersion;
use Flexipos\Access\Support\SelfChange;
use Flexipos\Access\Support\TenantConnection;
use Flexipos\Kernel\Schema\Support\RunnerClock;

/**
 * Attach an employee to a branch, optionally as their default (AC-35,
 * AC-52).
 *
 * **It bumps, and the reason is the claim rather than the permission.**
 * Attaching changes nothing about which keys the employee holds, so a
 * reader could reasonably expect no bump. AC-52 names this operation
 * anyway, and correctly: `branch_ids` is one of the five claims a token
 * carries (AC-58), and a token whose `branch_ids` is short by one branch is
 * stale in exactly the way `perm_version` exists to detect.
 *
 * **Demoting the previous default is part of this operation, not a second
 * one** (AC-35). `employee_branches` carries a stored generated column over
 * `is_default` with a unique index, so the database refuses two defaults
 * for one employee: a handler that inserted first and demoted afterwards
 * would meet that index and fail, and one that demoted in a separate
 * transaction would leave a window with no default at all. Both statements
 * are inside the one transaction here, in that order.
 *
 * **AC-34 applies and AC-36 does not.** An attachment confers no
 * permission of its own: it widens the set of scopes at which grants the
 * employee already holds can be used, and each of those grants was checked
 * against the actor when it was made. What it would let somebody do is
 * attach themselves to a branch and reach it with a role they hold at the
 * null scope - which is why the self-change rule is here.
 */
final class AttachBranchHandler
{
    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly AuditWriter $audit,
    ) {}

    public function handle(AttachBranch $command): void
    {
        $branchId = BranchScope::requireBranch($command->branchId);

        SelfChange::refuse($command->actorId, $command->employeeId, 'attach a branch');

        $connection = $this->tenant->get();

        $connection->transaction(function () use ($connection, $command, $branchId): void {
            EmployeeLookup::findLive($connection, $command->employeeId);

            $attached = $connection->table('employee_branches')
                ->where('employee_id', $command->employeeId)
                ->where('branch_id', $branchId)
                ->exists();

            if ($attached) {
                throw AccessRefused::validation(
                    "Employee [{$command->employeeId}] is already attached to branch [{$branchId}]."
                );
            }

            $now = $this->clock->timestamp();

            if ($command->isDefault) {
                $connection->table('employee_branches')
                    ->where('employee_id', $command->employeeId)
                    ->where('is_default', true)
                    ->update(['is_default' => false, 'updated_at' => $now]);
            }

            $connection->table('employee_branches')->insert([
                'employee_id' => $command->employeeId,
                'branch_id' => $branchId,
                'is_default' => $command->isDefault,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            PermVersion::bump($connection, [$command->employeeId]);

            $this->audit->write(
                $connection,
                $command->actorId,
                'branch_attached',
                'employee',
                $command->employeeId,
                null,
                ['branch_id' => $branchId, 'is_default' => $command->isDefault],
                $branchId,
            );
        });
    }
}
