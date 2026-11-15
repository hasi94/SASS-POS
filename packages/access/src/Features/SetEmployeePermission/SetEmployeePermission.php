<?php

declare(strict_types=1);

namespace Flexipos\Access\Features\SetEmployeePermission;

/**
 * One request to allow or deny a key for one person, at one scope (AC-32).
 *
 * `$allowed` is required and has no default. A per-person row is either an
 * allow or a deny and the two are opposites; a default would make the more
 * dangerous of the two - or the more surprising, depending which way the
 * default fell - reachable by omission.
 */
final class SetEmployeePermission
{
    public function __construct(
        public readonly string $employeeId,
        public readonly string $permissionKey,
        public readonly bool $allowed,
        public readonly ?string $branchId = null,
        public readonly ?string $actorId = null,
    ) {}
}
