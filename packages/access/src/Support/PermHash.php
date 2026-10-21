<?php

declare(strict_types=1);

namespace Flexipos\Access\Support;

use Illuminate\Database\Connection;

/**
 * The `perm_hash` claim: a fingerprint of an employee's `perm_version` and
 * the grants and denials that contribute to it (AC-54, D-23, plan Ruling 6).
 *
 * The canonical form, which the till will have to reproduce from the plan's
 * description rather than from this file:
 *
 * ```
 * line   := ("allow" | "deny") TAB <permission_key> TAB <scope>
 * scope  := <branch ULID, 26 chars>  |  "*"
 * body   := implode("\n", sort(array_unique(lines)))
 * input  := "flexipos.perm.v1" "\n" <perm_version, decimal, unpadded> "\n" body
 * hash   := bin2hex(sha256(input))
 * ```
 *
 * **Contributions, not resolved rights, and the difference is the whole
 * decision (D-23).** Hashing what the employee may *do* would mean
 * enumerating every branch, and "every branch except Y" has no finite form
 * without a `branches` table. Hashing what was *granted* is exact, finite
 * and available now.
 *
 * **What is therefore NOT claimed, and a reviewer must not read it in:**
 * that two configurations which resolve identically hash equally. The same
 * allow reached through a role and granted directly are two different
 * contributions and hash differently. "Equal hash iff equal rights" is the
 * natural overclaim and it is false here.
 *
 * **The version is inside the input, not beside it.** A client comparing
 * hashes must see a change when only `perm_version` moved - that is the
 * cheap signal that something about this person changed - and a hash that
 * ignored the version would be equal across a bump.
 *
 * **The tag is there so a format change is detectable rather than silent.**
 * If the line format ever moves, `flexipos.perm.v2` makes every hash differ
 * at once instead of two implementations quietly disagreeing about one.
 */
final class PermHash
{
    /** The format tag; the first line of every hash input. */
    public const TAG = 'flexipos.perm.v1';

    /** `allow` and `deny` are the two words a line can start with. */
    public const ALLOW = 'allow';

    public const DENY = 'deny';

    /**
     * The fingerprint of a version and a set of contributions.
     *
     * Each contribution is `[allowed, permission key, scope]`, with the
     * scope already rendered by {@see ScopeKey}. Order, duplication and
     * where each came from are all erased here, which is what makes the
     * hash a function of the rights and not of the rows.
     *
     * @param  list<array{bool, string, string}>  $contributions
     */
    public static function compute(int $permVersion, array $contributions): string
    {
        $lines = [];

        foreach ($contributions as [$allowed, $key, $scope]) {
            $lines[] = ($allowed ? self::ALLOW : self::DENY)."\t".$key."\t".$scope;
        }

        $lines = array_values(array_unique($lines));

        // SORT_STRING, not the default: a byte-wise ascending order is what
        // the published description says, and SORT_REGULAR would compare two
        // numeric-looking strings as numbers. No line can be numeric today -
        // every one starts with a word - so this is a guard against the
        // format moving, not a live defect.
        sort($lines, SORT_STRING);

        $body = implode("\n", $lines);

        // An employee with no contributions has an empty body and still a
        // defined hash (AC-55). That is deliberate: the five claims are
        // minted together or not at all, so there is no such thing as a
        // token with a version and no hash.
        return bin2hex(hash('sha256', self::TAG."\n".$permVersion."\n".$body, true));
    }

    /**
     * The fingerprint of one employee, read from the tenant database.
     *
     * Two sources, exactly as AC-54 defines them: one `allow` per (key,
     * scope) reachable through a role assignment, and one line per
     * `employee_permissions` row carrying its own `allowed` flag. Duplicates
     * - the same key granted by two roles, or by a role and directly - are
     * collapsed by {@see self::compute()}.
     *
     * A soft-deleted role contributes nothing (AC-43), here exactly as in
     * the resolver's role join. AC-22 stops the operations deleting a role
     * somebody holds, but that is not why the filter is here: a repair, a
     * restore or a race between `DeleteRole`'s count and an `AssignRole`
     * can still leave an assignment pointing at a deleted role, and the
     * resolver already ignores it. The resolver is the authority, so the
     * hash, `all_branches` and "my permissions" must read the same set of
     * contributions it does - one fact with two answers is the defect.
     */
    public static function forEmployee(Connection $connection, string $employeeId, int $permVersion): string
    {
        return self::compute($permVersion, self::contributionsFor($connection, $employeeId));
    }

    /**
     * One employee's contributions, as the hash's own notion of them.
     *
     * **Extracted so that "a contribution" has one definition.** AC-57's
     * `all_branches` claim is *"the employee holds an allowing
     * contribution at the null scope"* - the same word, and if the claim
     * builder answered it from its own pair of queries the two would be
     * one fact with two homes, free to disagree about a soft-deleted role
     * or about which tables contribute. They read the same list.
     *
     * @return list<array{bool, string, string}> allowed, key, scope
     */
    public static function contributionsFor(Connection $connection, string $employeeId): array
    {
        $contributions = [];

        $roleDerived = $connection->table('employee_roles')
            ->join('roles', 'roles.id', '=', 'employee_roles.role_id')
            ->join('role_permissions', 'role_permissions.role_id', '=', 'employee_roles.role_id')
            ->whereNull('roles.deleted_at')
            ->where('employee_roles.employee_id', $employeeId)
            ->get(['role_permissions.permission_key as permission_key', 'employee_roles.branch_id as branch_id']);

        foreach ($roleDerived as $row) {
            /** @var object{permission_key: string, branch_id: ?string} $row */
            $contributions[] = [true, (string) $row->permission_key, ScopeKey::of($row->branch_id)];
        }

        $perPerson = $connection->table('employee_permissions')
            ->where('employee_id', $employeeId)
            ->get(['permission_key', 'branch_id', 'allowed']);

        foreach ($perPerson as $row) {
            /** @var object{permission_key: string, branch_id: ?string, allowed: int|bool} $row */
            $contributions[] = [(bool) $row->allowed, (string) $row->permission_key, ScopeKey::of($row->branch_id)];
        }

        return $contributions;
    }
}
