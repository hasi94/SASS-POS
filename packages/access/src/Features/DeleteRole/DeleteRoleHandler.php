<?php

declare(strict_types=1);

namespace Flexipos\Access\Features\DeleteRole;

use Flexipos\Access\Exceptions\AccessRefused;
use Flexipos\Access\Support\AuditWriter;
use Flexipos\Access\Support\RoleLookup;
use Flexipos\Access\Support\TenantConnection;
use Flexipos\Kernel\Schema\Support\RunnerClock;

/**
 * Soft-delete a custom role that nobody holds (AC-22).
 *
 * **Soft deletion is the only deletion.** The row stays, which is what keeps
 * the key reserved (AC-19) and what lets an audit trail naming the role id
 * still resolve to something. Nothing in this package hard-deletes a role.
 *
 * **"In use" means assigned at any scope.** The check reads
 * `employee_roles` with no predicate on `branch_id`: a guard written over
 * null scopes only would let a role granted at one branch be deleted out
 * from under its holder, and `role_permissions` cascades on the role's
 * deletion in the schema, so the grant would quietly become a row pointing
 * at a role the list no longer shows.
 *
 * The assignment is **not** removed as a convenience. A deletion that
 * silently revoked somebody's access is the change an administrator did not
 * ask for; the refusal names the remedy instead (AC-22).
 */
final class DeleteRoleHandler
{
    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly AuditWriter $audit,
    ) {}

    public function handle(DeleteRole $command): void
    {
        $connection = $this->tenant->get();

        $connection->transaction(function () use ($connection, $command): void {
            $role = RoleLookup::findLive($connection, $command->roleId);

            RoleLookup::requireCustom($role);

            $holders = $connection->table('employee_roles')
                ->where('role_id', $command->roleId)
                ->count();

            if ($holders > 0) {
                throw AccessRefused::roleInUse(
                    "Role [{$role->key}] is assigned to {$holders} employee(s) and cannot be deleted."
                );
            }

            $deletedAt = $this->clock->timestamp();

            $connection->table('roles')->where('id', $command->roleId)->update([
                'deleted_at' => $deletedAt,
                'updated_at' => $deletedAt,
            ]);

            $this->audit->write(
                $connection,
                $command->actorId,
                'role_deleted',
                'role',
                $command->roleId,
                ['key' => (string) $role->key, 'deleted_at' => null],
                ['key' => (string) $role->key, 'deleted_at' => $deletedAt],
            );
        });
    }
}
