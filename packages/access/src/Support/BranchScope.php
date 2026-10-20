<?php

declare(strict_types=1);

namespace Flexipos\Access\Support;

use Flexipos\Access\Exceptions\AccessRefused;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;

/**
 * What a branch id is, and what an operation may do with one (D-11, D-30).
 *
 * **There is no `branches` table, and this class is where that fact is
 * stated once instead of six times.** A branch is a 26-character ULID and
 * nothing else: the format is checkable, existence is not. Four operations
 * of section E take a scope and every one of them would otherwise carry its
 * own opinion about what a well-formed id is and about whether a branch has
 * to exist - and the second question has no answer to carry.
 *
 * **A well-formed id naming no branch is accepted, deliberately** (AC-31,
 * D-30). The alternative is a check against a table that does not exist,
 * which would be shape only: it could refuse nothing, so it would pass
 * everything while reading as a guard. When FP-209 ships `branches`, the
 * existence check belongs here, in one place, beside the format rule.
 *
 * **Attachment, on the other hand, is checkable and is checked** (D-11).
 * `employee_branches` exists, and a grant scoped to a branch the employee
 * is not attached to is a grant nobody can ever use: `branch_ids` is what a
 * token carries, so the resolver would answer for a scope the claim never
 * mentions. The refusal is `not_attached` and not `validation`, because the
 * remedy is a different operation (AC-35's attach) rather than a corrected
 * request.
 */
final class BranchScope
{
    /**
     * The length of the only branch identifier this platform has.
     *
     * A ULID, like every other identifier here; `IdentifierColumn` stores
     * them as CHAR(26).
     */
    public const ULID_LENGTH = 26;

    /**
     * The tables in which a grant can name a branch.
     *
     * Published as a constant rather than written inline so that a test can
     * assert what was examined rather than take the sweep's word for it: an
     * instrument that enumerates publishes its domain (D-27).
     *
     * @var list<string>
     */
    public const GRANT_TABLES = ['employee_roles', 'employee_permissions'];

    /**
     * Check a scope's shape, returning it unchanged.
     *
     * Null passes through untouched: it is the all-branches scope and is a
     * meaningful value rather than a missing one (D-10).
     *
     * @throws AccessRefused validation, when a non-null id is not a ULID
     */
    public static function requireWellFormed(?string $branchId): ?string
    {
        if ($branchId === null) {
            return null;
        }

        if (mb_strlen($branchId) !== self::ULID_LENGTH || ! Str::isUlid($branchId)) {
            throw AccessRefused::validation(
                "Branch id [{$branchId}] is not a ".self::ULID_LENGTH.'-character ULID.'
            );
        }

        return $branchId;
    }

    /**
     * Check the shape of a scope that may not be the all-branches one.
     *
     * Attaching and detaching take a branch, never "all branches": an
     * `employee_branches` row *is* a branch, and the table's primary key
     * refuses a null one. Both call this rather than each narrowing
     * {@see self::requireWellFormed()}'s nullable result themselves, which
     * is two chances to narrow it with a cast instead of a refusal.
     *
     * @throws AccessRefused validation
     */
    public static function requireBranch(?string $branchId): string
    {
        $checked = self::requireWellFormed($branchId);

        if ($checked === null) {
            throw AccessRefused::validation('This operation needs a branch; the all-branches scope is not one.');
        }

        return $checked;
    }

    /**
     * Refuse unless the employee is attached to this branch (D-11).
     *
     * A null scope is attached to nothing and needs nothing: it means every
     * branch, including ones the employee is not attached to and ones that
     * do not exist yet.
     *
     * @throws AccessRefused not_attached
     */
    public static function requireAttached(Connection $connection, string $employeeId, ?string $branchId): void
    {
        if ($branchId === null) {
            return;
        }

        $attached = $connection->table('employee_branches')
            ->where('employee_id', $employeeId)
            ->where('branch_id', $branchId)
            ->exists();

        if (! $attached) {
            throw AccessRefused::notAttached(
                "Employee [{$employeeId}] is not attached to branch [{$branchId}]."
            );
        }
    }

    /**
     * Refuse a detach while any grant is still scoped to this branch
     * (D-11, AC-35).
     *
     * The other direction of {@see self::requireAttached()}, and it lives
     * beside it because together the two are one invariant: *every scoped
     * grant names a branch the employee is attached to*. Split across two
     * handlers, each would be read as a local precaution and the invariant
     * would be nobody's.
     *
     * **Both grant tables are consulted, and a sweep would not catch a
     * forgotten one.** An `employee_roles` row scoped to a detached branch
     * and an `employee_permissions` row scoped to one are the same defect -
     * a grant the resolver can answer for at a scope the token's
     * `branch_ids` no longer mentions - so they are checked in one place
     * and the list of tables to check is here, once.
     *
     * @throws AccessRefused has_scoped_grants
     */
    public static function requireNoScopedGrants(Connection $connection, string $employeeId, string $branchId): void
    {
        foreach (self::GRANT_TABLES as $table) {
            $count = $connection->table($table)
                ->where('employee_id', $employeeId)
                ->where('branch_id', $branchId)
                ->count();

            if ($count > 0) {
                throw AccessRefused::hasScopedGrants(
                    "Employee [{$employeeId}] still holds {$count} grant(s) in [{$table}] scoped to branch [{$branchId}]."
                );
            }
        }
    }

    /**
     * Narrow a query to exactly one scope.
     *
     * **One method, because the scope is part of the identity of a grant
     * and an operation that forgot it would act on every scope.** Dropping
     * this clause - which is the shape a hurried `if` collapses into -
     * deletes a role at every branch when one was asked for, and that
     * mutation reddens nine cases across four of this section's slices
     * (measured, T-010 step 13, M13-2b).
     *
     * **It is deliberately not here because `where()` cannot take a null**,
     * and that claim was measured and is false: Illuminate's query builder
     * maps `where($column, null)` onto `whereNull()` itself, so writing the
     * ternary out by hand in each caller would be correct too. What six
     * copies would cost is not correctness today but the single place where
     * "null is the all-branches scope, not a missing value" (D-10) is
     * expressed - and the one obvious site to edit if that ever stops being
     * true of the schema.
     */
    public static function at(Builder $query, ?string $branchId): Builder
    {
        return $branchId === null
            ? $query->whereNull('branch_id')
            : $query->where('branch_id', $branchId);
    }
}
