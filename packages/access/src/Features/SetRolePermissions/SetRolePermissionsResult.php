<?php

declare(strict_types=1);

namespace Flexipos\Access\Features\SetRolePermissions;

use Flexipos\Access\Support\PermVersion;

/**
 * What one re-permissioning did (AC-20, AC-52).
 *
 * `$bumped` is returned rather than left to be counted afterwards because
 * the holders are selected **inside** the bump statement (see
 * {@see PermVersion}), so the number of employees
 * affected is knowable at that moment and at no other: a caller counting
 * holders before or after would be counting a different set from the one
 * the statement touched.
 */
final class SetRolePermissionsResult
{
    /**
     * @param  list<string>  $permissionKeys  what the role holds now, sorted
     * @param  int  $bumped  how many employees had their perm_version raised
     */
    public function __construct(
        public readonly string $roleId,
        public readonly array $permissionKeys,
        public readonly int $bumped,
    ) {}
}
