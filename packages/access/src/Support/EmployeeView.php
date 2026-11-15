<?php

declare(strict_types=1);

namespace Flexipos\Access\Support;

/**
 * One employee as a reader sees them (AC-29, AC-65).
 *
 * **What is missing from this class is the criterion.** `pin_hash`,
 * `pin_failed_attempts` and `perm_version` are columns of `employees` and
 * none of them is a property here, so a renderer cannot leak one by
 * accident: there is nothing to leak. `hasPin` is the only thing about the
 * PIN that ever leaves the database - a boolean derived from whether a hash
 * is stored - because a till needs to know whether to prompt and nobody
 * needs the hash.
 *
 * That is why `ListEmployees` and `GetEmployee` both return this type and
 * neither returns a row: a row carries every column, and "do not render
 * these three" would then be a rule each renderer had to remember.
 *
 * **It is also the published domain of AC-29's sweep.** The test reads this
 * class's property list by reflection rather than looking for three
 * forbidden names, so a fourth secret rendered by accident fails it.
 */
final class EmployeeView
{
    /**
     * @param  list<AssignedRole>  $roles  sorted by key, each with its scope
     */
    public function __construct(
        public readonly string $id,
        public readonly string $code,
        public readonly string $firstName,
        public readonly ?string $lastName,
        public readonly string $displayName,
        public readonly string $status,
        public readonly bool $hasPin,
        public readonly array $roles,
    ) {}
}
