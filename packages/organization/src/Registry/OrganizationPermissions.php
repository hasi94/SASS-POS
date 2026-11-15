<?php

declare(strict_types=1);

namespace Flexipos\Organization\Registry;

use Flexipos\Contracts\Access\PermissionDeclaration;

/**
 * The eight permission keys this package owns (T-014 D-12).
 *
 * **All eight are `is_branch_scoped = false`, and that is a decision rather
 * than an oversight** (D-12, T-010 D-13). Making "manage terminals"
 * branch-scoped needs a rule for an employee attached to two branches, and no
 * document gives one. The routes ask the resolver at a null scope, so a
 * branch-scoped key on one of them would refuse every request; the host's
 * route sweep fails such a declaration at the commit that adds it.
 *
 * The declarations are values, not rows: the existing registry sync is the
 * only writer of `permissions`.
 */
final class OrganizationPermissions
{
    public const BRANCHES_VIEW = 'branches.view';

    public const BRANCHES_MANAGE = 'branches.manage';

    public const LOCATIONS_VIEW = 'locations.view';

    public const LOCATIONS_MANAGE = 'locations.manage';

    public const TERMINALS_VIEW = 'terminals.view';

    public const TERMINALS_MANAGE = 'terminals.manage';

    public const SETTINGS_VIEW = 'settings.view';

    public const SETTINGS_MANAGE = 'settings.manage';

    /** The module name every declaration here carries. */
    public const MODULE = 'organization';

    /**
     * @return list<PermissionDeclaration>
     */
    public static function declarations(): array
    {
        return [
            new PermissionDeclaration(
                self::BRANCHES_VIEW,
                self::MODULE,
                'branches',
                'List and read branches.',
                false,
            ),
            new PermissionDeclaration(
                self::BRANCHES_MANAGE,
                self::MODULE,
                'branches',
                'Create, update, deactivate and reactivate branches.',
                false,
            ),
            new PermissionDeclaration(
                self::LOCATIONS_VIEW,
                self::MODULE,
                'locations',
                'List and read locations and warehouses.',
                false,
            ),
            new PermissionDeclaration(
                self::LOCATIONS_MANAGE,
                self::MODULE,
                'locations',
                'Create warehouses, update, deactivate and reactivate locations.',
                false,
            ),
            new PermissionDeclaration(
                self::TERMINALS_VIEW,
                self::MODULE,
                'terminals',
                'List and read terminals and their devices.',
                false,
            ),
            // Separate from terminals.view, and the one that can lock a till
            // out: revoking a terminal and issuing an activation code are both
            // under it (D-12).
            new PermissionDeclaration(
                self::TERMINALS_MANAGE,
                self::MODULE,
                'terminals',
                'Create, update, pause, resume and revoke terminals and issue activation codes.',
                false,
            ),
            new PermissionDeclaration(
                self::SETTINGS_VIEW,
                self::MODULE,
                'settings',
                'Read the effective settings of a company, branch or terminal.',
                false,
            ),
            // Writes to settings, including a secret's value, which no read returns.
            new PermissionDeclaration(
                self::SETTINGS_MANAGE,
                self::MODULE,
                'settings',
                'Set and clear settings at company, branch and terminal scope.',
                false,
            ),
        ];
    }
}
