<?php

declare(strict_types=1);

namespace Flexipos\Access\Features\ListRoles;

use Flexipos\Access\Support\RoleView;
use Flexipos\Access\Support\TenantConnection;

/**
 * The tenant's live roles, by key, each with its permission keys (AC-23).
 *
 * **Two queries, not one per role.** The permissions are read in a single
 * pass over `role_permissions` and grouped here, so the cost of the list
 * does not grow with the number of roles. There is no eager-loading
 * machinery and no model: a read of two tables is a read of two tables.
 *
 * Soft-deleted roles are excluded, which is what makes a deleted role
 * "gone" to every reader while its key stays reserved (AC-19, AC-22).
 */
final class ListRolesHandler
{
    public function __construct(private readonly TenantConnection $tenant) {}

    /**
     * @return list<RoleView>
     */
    public function handle(ListRoles $query): array
    {
        $connection = $this->tenant->get();

        $roles = $connection->table('roles')
            ->whereNull('deleted_at')
            ->orderBy('key')
            ->get(['id', 'key', 'name', 'description', 'is_system']);

        /** @var array<string, list<string>> $keysByRole */
        $keysByRole = [];

        foreach ($connection->table('role_permissions')->orderBy('permission_key')->get() as $row) {
            /** @var object{role_id: string, permission_key: string} $row */
            $keysByRole[(string) $row->role_id][] = (string) $row->permission_key;
        }

        $views = [];

        foreach ($roles as $role) {
            /** @var object{id: string, key: string, name: string, description: ?string, is_system: int|bool} $role */
            $views[] = new RoleView(
                (string) $role->id,
                (string) $role->key,
                (string) $role->name,
                $role->description === null ? null : (string) $role->description,
                (bool) $role->is_system,
                $keysByRole[(string) $role->id] ?? [],
            );
        }

        return $views;
    }
}
