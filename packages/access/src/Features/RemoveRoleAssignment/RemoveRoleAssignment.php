<?php

declare(strict_types=1);

namespace Flexipos\Access\Features\RemoveRoleAssignment;

/**
 * One request to take a role away from an employee, at one scope (AC-33).
 *
 * The scope is part of the identity of the thing being removed, exactly as
 * it is part of the identity of the thing assigned: removing `shift_lead`
 * "from this employee" is not an operation, because they may hold it at a
 * branch and at the all-branches scope and those are two grants.
 */
final class RemoveRoleAssignment
{
    public function __construct(
        public readonly string $employeeId,
        public readonly string $roleId,
        public readonly ?string $branchId = null,
        public readonly ?string $actorId = null,
    ) {}
}
