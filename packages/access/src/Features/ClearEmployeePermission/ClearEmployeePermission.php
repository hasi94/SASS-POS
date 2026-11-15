<?php

declare(strict_types=1);

namespace Flexipos\Access\Features\ClearEmployeePermission;

/**
 * One request to remove a per-person allow or deny, at one scope (AC-33).
 *
 * There is no `$allowed` here on purpose: a scope holds at most one
 * per-person row for a key (the null-safe unique key makes that true), so
 * naming the scope names the row, and asking the caller which of the two
 * they believe is there would let them clear nothing while believing they
 * cleared a deny.
 */
final class ClearEmployeePermission
{
    public function __construct(
        public readonly string $employeeId,
        public readonly string $permissionKey,
        public readonly ?string $branchId = null,
        public readonly ?string $actorId = null,
    ) {}
}
