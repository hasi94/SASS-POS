<?php

declare(strict_types=1);

namespace Flexipos\Access\Features\CreateEmployee;

/**
 * One request to create an employee (AC-24, AC-25).
 *
 * **Identity and access only (D-14).** There is no NIC, no join date, no
 * salary and no branch on this command, because there is none on the table:
 * the brief is employees, roles, permissions and PINs, and every HR column
 * is additive later.
 *
 * **No `pinHash` and no `permVersion`.** A PIN is set by its own slice,
 * behind its own permission (`employees.set_pin`, D-13), and the version is
 * the database's to start and this package's to raise one statement at a
 * time. A create that could set either would be a second door to a decision
 * that has its own.
 *
 * `$status` is accepted because AC-25 requires a status outside the
 * declared set to be refused here, which is only a rule if a status can be
 * given at all; omitting it means `active` (AC-24).
 */
final class CreateEmployee
{
    public function __construct(
        public readonly string $code,
        public readonly string $firstName,
        public readonly ?string $lastName = null,
        public readonly ?string $displayName = null,
        public readonly ?string $userId = null,
        public readonly ?string $status = null,
        public readonly ?string $actorId = null,
    ) {}
}
