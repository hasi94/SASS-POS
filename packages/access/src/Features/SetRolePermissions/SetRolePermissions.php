<?php

declare(strict_types=1);

namespace Flexipos\Access\Features\SetRolePermissions;

/**
 * One request to make a role hold exactly these permission keys (AC-20).
 *
 * **Set, not add and not remove.** The whole set travels in one command
 * because "replace" is the operation AC-20 describes and because a pair of
 * add/remove commands would let two administrators interleave into a set
 * neither of them asked for. An empty list is valid and means the role
 * holds nothing.
 */
final class SetRolePermissions
{
    /**
     * @param  list<string>  $permissionKeys
     */
    public function __construct(
        public readonly string $roleId,
        public readonly array $permissionKeys,
        public readonly ?string $actorId = null,
    ) {}
}
