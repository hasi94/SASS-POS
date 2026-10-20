<?php

declare(strict_types=1);

namespace Flexipos\Access\Support;

use Flexipos\Access\Exceptions\AccessRefused;
use Illuminate\Database\Connection;

/**
 * Finding a role, and the one place `is_system` is read (AC-21).
 *
 * **Three operations refuse a system role - rename, re-permission and
 * delete - and they must refuse it for the same reason.** Three copies of
 * `if ($role->is_system)` is the shape where a fourth operation arrives and
 * only two of them are remembered; and the refusal has to read the **flag on
 * the row**, never the key `owner`, so that a shop naming its own role
 * `owner` does not thereby make it immune and a renamed system role does not
 * escape.
 *
 * **A role that is soft-deleted is a role that does not exist**, for every
 * operation that is not the registry's own. It is refused `validation`
 * rather than with a reason of its own: the closed set (D-26) has no
 * `not_found`, and inventing one for this would publish a fourteenth
 * refusal across the whole platform to serve one query. `validation` is also
 * the honest description - the request named something that is not there.
 *
 * Static methods over a connection the caller already holds, because every
 * caller is inside its own transaction on that connection and a lookup that
 * resolved its own would be reading outside it.
 */
final class RoleLookup
{
    /**
     * The live role with this id.
     *
     * @return object{id: string, key: string, name: string, description: ?string, is_system: int|bool}
     *
     * @throws AccessRefused when no live role has that id
     */
    public static function findLive(Connection $connection, string $roleId): object
    {
        $role = $connection->table('roles')
            ->where('id', $roleId)
            ->whereNull('deleted_at')
            ->first();

        if ($role === null) {
            throw AccessRefused::validation("No live role has id [{$roleId}].");
        }

        /** @var object{id: string, key: string, name: string, description: ?string, is_system: int|bool} $role */
        return $role;
    }

    /**
     * Refuse the operation if this role belongs to the platform (AC-21).
     *
     * @param  object{key: string, is_system: int|bool}  $role
     *
     * @throws AccessRefused
     */
    public static function requireCustom(object $role): void
    {
        if ((bool) $role->is_system) {
            throw AccessRefused::systemRole(
                "Role [{$role->key}] is a system role and cannot be renamed, re-permissioned or deleted."
            );
        }
    }
}
