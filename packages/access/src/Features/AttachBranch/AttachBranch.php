<?php

declare(strict_types=1);

namespace Flexipos\Access\Features\AttachBranch;

/**
 * One request to attach an employee to a branch (AC-35).
 *
 * `$branchId` is **not** nullable here, unlike every other command of this
 * section. Elsewhere null is the all-branches scope and a value in its own
 * right (D-10); an attachment to "all branches" is not a row, because
 * `employee_branches` is the list of branches a token carries and a list
 * cannot hold "every".
 */
final class AttachBranch
{
    public function __construct(
        public readonly string $employeeId,
        public readonly string $branchId,
        public readonly bool $isDefault = false,
        public readonly ?string $actorId = null,
    ) {}
}
