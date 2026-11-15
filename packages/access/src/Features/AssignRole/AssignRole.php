<?php

declare(strict_types=1);

namespace Flexipos\Access\Features\AssignRole;

/**
 * One request to give an employee a role, at one scope (AC-30, AC-31).
 *
 * `$branchId` null is the all-branches scope and is a value, not an absence
 * (D-10): the same role assigned at null and at a branch are two different
 * assignments, and the table's null-safe unique key keeps them apart.
 */
final class AssignRole
{
    public function __construct(
        public readonly string $employeeId,
        public readonly string $roleId,
        public readonly ?string $branchId = null,
        public readonly ?string $actorId = null,
    ) {}
}
