<?php

declare(strict_types=1);

namespace Flexipos\Access\Registry;

use Flexipos\Contracts\Access\PermissionDeclaration;

/**
 * The one permission key reading audit records needs (T-017 Ruling 9,
 * AC-5, D-8).
 *
 * **A separate class from {@see AccessPermissions}**, so that class's own
 * "six keys" contract - and the tests that pin exactly those six - do not
 * move. `AccessServiceProvider::permissionDeclarations()` merges this class
 * in alongside it.
 *
 * `audit.read` is company-wide (not branch-scoped) and defaults closed like
 * every key in `AccessPermissions`: no automatic grant to any role.
 */
final class AuditPermissions
{
    public const AUDIT_READ = 'audit.read';

    /** The module every declaration here carries. */
    public const MODULE = 'access';

    /**
     * @return list<PermissionDeclaration>
     */
    public static function declarations(): array
    {
        return [
            new PermissionDeclaration(
                self::AUDIT_READ,
                self::MODULE,
                'audit',
                'Search and export the tenant audit trail.',
                false,
            ),
        ];
    }
}
