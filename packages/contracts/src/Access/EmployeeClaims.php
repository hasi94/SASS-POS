<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Access;

use InvalidArgumentException;

/**
 * The five employee-derived token claims, as one value (D-2).
 *
 * **All-or-none is a property of this type, not of three call sites.** D-2
 * requires `employee`, `branch_ids`, `all_branches`, `perm_version` and
 * `perm_hash` to be minted together or not at all; expressed as five
 * parameters on a handler, "together" is a rule somebody has to remember at
 * selection, at tenant refresh, and at whatever mints a token next. Expressed
 * as one object that cannot be built half-formed, it is a rule the type system
 * keeps. A membership with no linked employee produces **no instance** rather
 * than an instance of nulls.
 *
 * **`branchIds` is explicit attachments only, and that meaning is final.**
 * D-2: it is never expanded to "every branch" for an owner, because there is
 * no `branches` table to expand from and defining the empty list as "all"
 * would redefine the claim the day FP-209 lands. The "all" case is carried by
 * {@see self::$allBranches}, which is why both exist.
 */
final class EmployeeClaims
{
    /** `perm_hash` is a full SHA-256 in lower-case hex, never truncated (Ruling 6). */
    public const HASH_LENGTH = 64;

    /**
     * @param  list<string>  $branchIds  distinct, sorted ascending, possibly empty
     */
    public function __construct(
        public readonly string $employee,
        public readonly array $branchIds,
        public readonly bool $allBranches,
        public readonly int $permVersion,
        public readonly string $permHash,
    ) {
        if ($employee === '') {
            throw new InvalidArgumentException('Employee claims need an employee id.');
        }

        $sorted = array_values(array_unique($branchIds));
        sort($sorted);

        if ($sorted !== $branchIds) {
            throw new InvalidArgumentException('Branch ids must be distinct and sorted ascending.');
        }

        if ($permVersion < 0) {
            throw new InvalidArgumentException('A permission version is never negative.');
        }

        if (preg_match('/^[0-9a-f]{'.self::HASH_LENGTH.'}$/', $permHash) !== 1) {
            throw new InvalidArgumentException(
                'A permission hash is '.self::HASH_LENGTH.' lower-case hexadecimal characters, never truncated.'
            );
        }
    }

    /**
     * The claim set as it is written into a token.
     *
     * The keys are the wire names, which are snake_case and are a published
     * contract; the property names are PHP's. Keeping the mapping here means
     * there is one place that knows both.
     *
     * @return array{employee: string, branch_ids: list<string>, all_branches: bool, perm_version: int, perm_hash: string}
     */
    public function toClaims(): array
    {
        return [
            'employee' => $this->employee,
            'branch_ids' => $this->branchIds,
            'all_branches' => $this->allBranches,
            'perm_version' => $this->permVersion,
            'perm_hash' => $this->permHash,
        ];
    }
}
