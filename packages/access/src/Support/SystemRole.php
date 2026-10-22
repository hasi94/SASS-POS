<?php

declare(strict_types=1);

namespace Flexipos\Access\Support;

use Illuminate\Database\Connection;

/**
 * The roles the platform owns rather than the shop (D-12).
 *
 * There is exactly one, `owner`, and no catalogue of manager / cashier /
 * waiter is seeded: nothing in either design document names those, and
 * inventing them would invent business rules (D-12, open question 1).
 *
 * **It is a class of constants rather than three literals in the sync.** The
 * key is read by the sync that seeds the role, by the refusals that protect
 * a system role from being renamed, re-permissioned or deleted (AC-21), and
 * by the last-owner safeguard (AC-37); a second literal is how two of those
 * come to disagree.
 *
 * `is_system` on the row, not this list, is what the refusals read: a shop
 * must not be able to make its own role immune by naming it `owner`, and a
 * later platform role must not need every guard edited.
 */
final class SystemRole
{
    public const OWNER_KEY = 'owner';

    public const OWNER_NAME = 'Owner';

    public const OWNER_DESCRIPTION = 'Holds every permission the application declares.';

    /**
     * Does this employee hold any role with `roles.is_system = true`, at any
     * scope (T-014 D-8)?
     *
     * The PIN-reset rule asks it of the target, and it reads the column and
     * never the key `owner`: a shop must not be able to opt a role out of the
     * rule by naming it something else. A soft-deleted role is excluded, as the
     * resolver excludes it, and the employee's status is deliberately not
     * consulted - a suspended person's rights return on reactivation.
     */
    public static function isHeldBy(Connection $connection, string $employeeId): bool
    {
        return $connection->table('employee_roles')
            ->join('roles', 'roles.id', '=', 'employee_roles.role_id')
            ->where('employee_roles.employee_id', $employeeId)
            ->where('roles.is_system', true)
            ->whereNull('roles.deleted_at')
            ->exists();
    }
}
