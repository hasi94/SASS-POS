<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Access;

/**
 * What {@see TenantAccessBootstrap::ensureOwnerEmployee()} needs to create
 * the first owner employee of a tenant (T-026 D-24, Ruling 5, Ruling 7).
 *
 * **No `code` here.** `employees.code` for this one employee is always the
 * fixed literal `OWNER` (D-24) - the resume key `create_owner` reads back by
 * - so there is nothing for a caller to submit and nothing that could ever
 * disagree with it.
 *
 * `displayName` is optional: when it is null the employee's display name
 * defaults from the local part of the owner's email (AC-29), a decision this
 * value object does not make - the caller (platform's `create_owner` step)
 * already knows the email and passes its local part down as `firstName`
 * when no name was supplied.
 */
final class OwnerSeed
{
    public function __construct(
        public readonly string $firstName,
        public readonly ?string $displayName = null,
    ) {}
}
