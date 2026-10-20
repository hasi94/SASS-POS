<?php

declare(strict_types=1);

namespace Flexipos\Access\Features\DetachBranch;

use Flexipos\Access\Features\AttachBranch\AttachBranch;

/**
 * One request to detach an employee from a branch (AC-35, D-11).
 *
 * The inverse of {@see AttachBranch}
 * and, like it, `$branchId` is not nullable: there is no such thing as
 * detaching from the all-branches scope.
 */
final class DetachBranch
{
    public function __construct(
        public readonly string $employeeId,
        public readonly string $branchId,
        public readonly ?string $actorId = null,
    ) {}
}
