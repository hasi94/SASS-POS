<?php

declare(strict_types=1);

namespace Flexipos\Access\Features\SetEmployeePermission;

use Flexipos\Access\Exceptions\AccessRefused;
use Flexipos\Access\Support\AuditWriter;
use Flexipos\Access\Support\BranchScope;
use Flexipos\Access\Support\EmployeeLookup;
use Flexipos\Access\Support\Escalation;
use Flexipos\Access\Support\PermVersion;
use Flexipos\Access\Support\SelfChange;
use Flexipos\Access\Support\TenantConnection;
use Flexipos\Contracts\Organization\BranchDirectory;
use Illuminate\Support\Str;

/**
 * Put one per-person allow or deny on an employee (AC-32, AC-52).
 *
 * **Three refusals, and they are three different facts about the request.**
 *
 * - an unregistered key is `validation`: the key is misspelled or the
 *   module declaring it is not booted, and the remedy is a corrected field;
 * - a **non-branch-scoped** key named with a branch is
 *   `scope_not_applicable` (D-8 (e)): such a key can only ever be held
 *   company-wide, so attaching the branch would not help and a caller told
 *   `validation` would go looking at the spelling;
 * - a branch the employee is not attached to is `not_attached` (D-11): the
 *   request is well formed and the remedy is a different operation.
 *
 * They are three members of the closed set rather than one, because a
 * caller that cannot tell them apart cannot act on any of them.
 *
 * **Setting replaces** (AC-32): a second set for the same employee, key and
 * scope overwrites the first, including flipping an allow to a deny. It is
 * written as a delete and an insert inside the transaction rather than an
 * upsert, so the row carries a fresh id and there is one code path whether
 * or not a row was there - an `update`-or-`insert` branch is where the two
 * halves come to disagree about what they write.
 *
 * **Step 14's two guards are here, and only one of them is conditional.**
 * The self-change rule (AC-34) applies to an allow and to a deny alike -
 * an administrator denying themselves a key is still changing their own
 * access, and still the person who should not be doing it unwitnessed. The
 * escalation rule (AC-36) applies to an **allow** only: taking a right
 * away raises nobody, and requiring the actor to hold a key before they
 * could deny it would stop them revoking a permission they were never
 * given.
 */
final class SetEmployeePermissionHandler
{
    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly Escalation $escalation,
        private readonly AuditWriter $audit,
        private readonly BranchDirectory $branches,
    ) {}

    public function handle(SetEmployeePermission $command): void
    {
        $key = trim($command->permissionKey);
        $branchId = BranchScope::requireWellFormed($command->branchId);

        SelfChange::refuse($command->actorId, $command->employeeId, 'set a permission');

        $connection = $this->tenant->get();

        $connection->transaction(function () use ($connection, $command, $key, $branchId): void {
            EmployeeLookup::findLive($connection, $command->employeeId);

            $permission = $connection->table('permissions')->where('key', $key)->first();

            if ($permission === null) {
                throw AccessRefused::validation("Permission key [{$key}] is not registered.");
            }

            /** @var object{is_branch_scoped: int|bool} $permission */
            if ($branchId !== null && ! (bool) $permission->is_branch_scoped) {
                throw AccessRefused::scopeNotApplicable(
                    "Permission [{$key}] is not branch-scoped and can only be held company-wide."
                );
            }

            BranchScope::requireUsable($this->branches, $branchId);
            BranchScope::requireAttached($connection, $command->employeeId, $branchId);

            if ($command->allowed) {
                $this->escalation->requireHeld($command->actorId, $key, $branchId);
            }

            $previous = BranchScope::at(
                $connection->table('employee_permissions')
                    ->where('employee_id', $command->employeeId)
                    ->where('permission_key', $key),
                $branchId
            )->value('allowed');

            BranchScope::at(
                $connection->table('employee_permissions')
                    ->where('employee_id', $command->employeeId)
                    ->where('permission_key', $key),
                $branchId
            )->delete();

            $connection->table('employee_permissions')->insert([
                'id' => (string) Str::ulid(),
                'employee_id' => $command->employeeId,
                'permission_key' => $key,
                'branch_id' => $branchId,
                'allowed' => $command->allowed,
            ]);

            PermVersion::bump($connection, [$command->employeeId]);

            $this->audit->write(
                $connection,
                $command->actorId,
                'employee_permission_set',
                'employee',
                $command->employeeId,
                $previous === null
                    ? null
                    : ['permission_key' => $key, 'branch_id' => $branchId, 'allowed' => (bool) $previous],
                ['permission_key' => $key, 'branch_id' => $branchId, 'allowed' => $command->allowed],
                $branchId,
            );
        });
    }
}
