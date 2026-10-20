<?php

declare(strict_types=1);

namespace Flexipos\Access\Features\RenameRole;

use Flexipos\Access\Exceptions\AccessRefused;
use Flexipos\Access\Support\AuditWriter;
use Flexipos\Access\Support\RoleLookup;
use Flexipos\Access\Support\RoleView;
use Flexipos\Access\Support\TenantConnection;
use Flexipos\Kernel\Schema\Support\RunnerClock;

/**
 * Rename a custom role (AC-21), and bump nobody (AC-52).
 *
 * **The absence of a bump is the criterion, not an omission.** AC-52 lists a
 * role rename among the changes that must **not** raise `perm_version`: a
 * bump tells every live token that its claims are stale, and spending it on
 * a cosmetic change would retire sessions for nothing. There is deliberately
 * no call to `PermVersion` in this file, and the test asserts a holder's
 * version is unmoved rather than inferring it from the absence of a line
 * here.
 */
final class RenameRoleHandler
{
    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly AuditWriter $audit,
    ) {}

    public function handle(RenameRole $command): RoleView
    {
        $name = trim($command->name);
        $description = $command->description === null ? null : trim($command->description);

        if ($name === '') {
            throw AccessRefused::validation('A role needs a name.');
        }

        if (mb_strlen($name) > 100) {
            throw AccessRefused::validation('A role name is at most 100 characters.');
        }

        if ($description !== null && mb_strlen($description) > 255) {
            throw AccessRefused::validation('A role description is at most 255 characters.');
        }

        $connection = $this->tenant->get();

        return $connection->transaction(function () use ($connection, $command, $name, $description): RoleView {
            $role = RoleLookup::findLive($connection, $command->roleId);

            RoleLookup::requireCustom($role);

            $connection->table('roles')->where('id', $command->roleId)->update([
                'name' => $name,
                'description' => $description,
                'updated_at' => $this->clock->timestamp(),
            ]);

            $keys = $connection->table('role_permissions')
                ->where('role_id', $command->roleId)
                ->orderBy('permission_key')
                ->pluck('permission_key')
                ->map(static fn ($key): string => (string) $key)
                ->all();

            $this->audit->write(
                $connection,
                $command->actorId,
                'role_renamed',
                'role',
                (string) $role->id,
                ['name' => (string) $role->name, 'description' => $role->description],
                ['name' => $name, 'description' => $description],
            );

            return new RoleView(
                (string) $role->id,
                (string) $role->key,
                $name,
                $description,
                false,
                $keys,
            );
        });
    }
}
