<?php

declare(strict_types=1);

namespace Flexipos\Access\Features\DeleteRole;

/**
 * One request to delete a custom role (AC-22).
 *
 * There is no `force` field and there will not be one: AC-22 refuses a role
 * that is still assigned, and a flag that skipped the check would be a
 * second, quieter door to the same decision.
 */
final class DeleteRole
{
    public function __construct(
        public readonly string $roleId,
        public readonly ?string $actorId = null,
    ) {}
}
