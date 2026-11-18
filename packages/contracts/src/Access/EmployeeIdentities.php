<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Access;

use LogicException;

/**
 * Bulk and paged reads of employee identity, for a package that may not
 * query `employees` (T-038 plan Ruling 3, D-44).
 *
 * A separate interface from {@see EmployeeDirectory}, because adding a
 * method there would break every test double that implements it. Implemented
 * by `packages/access`, so every statement naming `employees` during an HR
 * operation is attributable to an access class.
 */
interface EmployeeIdentities
{
    /**
     * The live employees among `$ids`, keyed by id. An id that names no live
     * employee is omitted. Callers pass at most one page of ids.
     *
     * @param  list<string>  $ids
     * @return array<string, EmployeeIdentity>
     */
    public function identitiesOf(array $ids): array;

    /**
     * One keyset page of the live employees among `$among`, ordered by
     * `code, id`.
     *
     * `$nameContains` matches `display_name`, `first_name`, `last_name` and
     * `code`, case-insensitively, with `\`, `%` and `_` taken literally.
     * `$afterCode` and `$afterId` are the previous page's last row, passed
     * together or not at all.
     *
     * @param  list<string>  $among
     * @return list<EmployeeIdentity>
     */
    public function pageAmong(
        array $among,
        ?string $status,
        ?string $nameContains,
        ?string $afterCode,
        ?string $afterId,
        int $limit,
    ): array;

    /**
     * The employee statuses, so a caller validates a `status` filter without
     * a second home for the list.
     *
     * @return list<string>
     */
    public function statuses(): array;

    /**
     * Take a shared lock on the employee's row and report whether it is live.
     *
     * @throws LogicException outside a transaction
     */
    public function holdLive(string $id): bool;
}
