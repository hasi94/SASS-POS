<?php

declare(strict_types=1);

namespace Flexipos\Access\Features\CreateRole;

/**
 * One request to create a custom role (AC-19).
 *
 * A role is created with **no permissions**: there is no `permissionKeys`
 * field, because granting them is `SetRolePermissions`, where AC-36's
 * no-escalation check will live (step 14). A create that could also grant
 * would be a second door into the same decision, and the guard would have to
 * be remembered in two places.
 *
 * `$actorId` is the employee making the change. Nothing in this step reads
 * it: it is the audit row's subject (AC-70, step 16). It is carried from the
 * first day so that step does not have to re-open every command in the
 * package, and it is nullable because the registry sync creates `owner` with
 * no human behind it.
 */
final class CreateRole
{
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly ?string $description = null,
        public readonly ?string $actorId = null,
    ) {}
}
