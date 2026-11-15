<?php

declare(strict_types=1);

namespace Flexipos\Access\Registry;

use Flexipos\Contracts\Access\PermissionDeclaration;

/**
 * The six permission keys this task owns (AC-14, D-13).
 *
 * One key per capability T-010 itself guards, and no others: a catalogue of
 * keys for operations nobody has written would be a set of promises the
 * resolver could answer and no route could honour.
 *
 * **All six are `is_branch_scoped = false`, and that is a decision rather
 * than an oversight (D-13).** Making "manage employees" branch-scoped needs a
 * rule for an employee attached to two branches, and no document gives one.
 * The branch mechanism is therefore real and proved against **fixture**
 * branch-scoped keys (AC-39, AC-41); a reader must not take the absence of a
 * branch-scoped production key for the absence of the feature.
 *
 * The declarations are values, not rows. Nothing here writes to
 * `permissions`: the sync slice does that, idempotently, and it is the only
 * writer.
 */
final class AccessPermissions
{
    public const EMPLOYEES_VIEW = 'employees.view';

    public const EMPLOYEES_MANAGE = 'employees.manage';

    public const EMPLOYEES_SET_PIN = 'employees.set_pin';

    public const ROLES_VIEW = 'roles.view';

    public const ROLES_MANAGE = 'roles.manage';

    public const ACCESS_ASSIGN = 'access.assign';

    /** The module name every declaration here carries. */
    public const MODULE = 'access';

    /**
     * @return list<PermissionDeclaration>
     */
    public static function declarations(): array
    {
        return [
            new PermissionDeclaration(
                self::EMPLOYEES_VIEW,
                self::MODULE,
                'employees',
                'List and read employees.',
                false,
            ),
            new PermissionDeclaration(
                self::EMPLOYEES_MANAGE,
                self::MODULE,
                'employees',
                'Create, update, change the status of and delete employees.',
                false,
            ),
            // Separate from employees.manage on purpose: setting somebody's
            // till PIN is handing out a credential, and a shop that lets a
            // supervisor edit names should be able to withhold that.
            new PermissionDeclaration(
                self::EMPLOYEES_SET_PIN,
                self::MODULE,
                'employees',
                'Set or replace an employee cashier PIN.',
                false,
            ),
            new PermissionDeclaration(
                self::ROLES_VIEW,
                self::MODULE,
                'roles',
                'List and read roles and the permissions they hold.',
                false,
            ),
            new PermissionDeclaration(
                self::ROLES_MANAGE,
                self::MODULE,
                'roles',
                'Create, rename, re-permission and delete custom roles.',
                false,
            ),
            // The one key that can widen anybody else's access, which is why
            // it is not folded into roles.manage: holding it is what the
            // no-escalation rule (D-19 (c)) is written about.
            new PermissionDeclaration(
                self::ACCESS_ASSIGN,
                self::MODULE,
                'access',
                'Assign roles, per-person permissions and branch attachments to employees.',
                false,
            ),
        ];
    }
}
