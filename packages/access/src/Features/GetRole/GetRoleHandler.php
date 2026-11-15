<?php

declare(strict_types=1);

namespace Flexipos\Access\Features\GetRole;

use Flexipos\Access\Support\RoleLookup;
use Flexipos\Access\Support\RoleView;
use Flexipos\Access\Support\TenantConnection;

/**
 * One role, with its permission keys (AC-23).
 *
 * A soft-deleted role is **refused**, not returned with a flag:
 * {@see RoleLookup::findLive()} is the one place that decides what "live"
 * means, so the detail query and the three mutating operations cannot
 * disagree about whether a deleted role exists.
 */
final class GetRoleHandler
{
    public function __construct(private readonly TenantConnection $tenant) {}

    public function handle(GetRole $query): RoleView
    {
        $connection = $this->tenant->get();

        $role = RoleLookup::findLive($connection, $query->roleId);

        $keys = $connection->table('role_permissions')
            ->where('role_id', $query->roleId)
            ->orderBy('permission_key')
            ->pluck('permission_key')
            ->map(static fn ($key): string => (string) $key)
            ->all();

        return new RoleView(
            (string) $role->id,
            (string) $role->key,
            (string) $role->name,
            $role->description === null ? null : (string) $role->description,
            (bool) $role->is_system,
            $keys,
        );
    }
}
