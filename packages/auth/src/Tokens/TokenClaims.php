<?php

declare(strict_types=1);

namespace Flexipos\Auth\Tokens;

use Flexipos\Contracts\Access\EmployeeClaims;
use InvalidArgumentException;

/**
 * The exact claim sets of D-10, AC-28 and T-010's AC-56, as three named
 * shapes, so that "exactly" is a value and not a literal repeated in three
 * handlers.
 *
 * **T-009 minted the short tenant set and said why; T-010 is the task that
 * removed the reason.** The five employee-derived claims - `employee`,
 * `branch_ids`, `all_branches`, `perm_version`, `perm_hash` - were withheld
 * because they come from the tenant's `employees` and `roles` tables, which
 * did not exist. They do now, so the third shape mints them, and the
 * withholding did what it was for: a claim nobody could populate was never
 * minted with a *meaning* that later had to change.
 *
 * **There are three shapes and not two-and-a-half.** A tenant token either
 * carries all five or none of them, never some (AC-58). The type that makes
 * that true is {@see EmployeeClaims}, which cannot be built half-formed, and
 * the consequence here is that a token carrying `employee` without
 * `perm_version` is not merely unminted - it is **refused at verification**,
 * because its claim names match neither legal set.
 *
 * **`jti` is a correlation id, not a revocation handle.** Nothing stores it
 * and nothing checks it on verification, so it must not be read as implying
 * revocation we do not have - AC-48 states the access-token window as accepted
 * behaviour.
 *
 * **The allowed-name set is derived from the minting shape.** `allowedNames()`
 * builds a specimen through the same private builder the real claims come
 * from and returns its keys. A second hand-written list would agree with the
 * minter on the day it was written and disagree the day a claim was added,
 * which is precisely the drift AC-28 is asserted against.
 *
 * **Set equality, never containment**: an extra `database` claim fails, and so
 * does a missing `jti`. A "contains" comparison passes the first and a
 * "subset" comparison passes the second, so both directions are tested.
 */
final class TokenClaims
{
    public const TYPE_PLATFORM = 'platform';

    public const TYPE_TENANT = 'tenant';

    /**
     * A terminal's token (T-014 AC-33, D-19): a machine principal, minted at
     * activation and at every terminal refresh. It carries `tenant`,
     * `terminal_id` and `branch_ids` holding exactly the terminal's one branch,
     * and **none** of the five employee claims - a till is not a person, and a
     * token that carried `employee` would be taken for one.
     */
    public const TYPE_TERMINAL = 'terminal';

    /**
     * The token types, which are also the claim shapes.
     */
    public const TYPES = [self::TYPE_PLATFORM, self::TYPE_TENANT, self::TYPE_TERMINAL];

    /**
     * The platform access token's claims: AC-28's set exactly.
     *
     * @return array<string, mixed>
     */
    public static function platform(string $userId, string $jti, int $issuedAt, int $ttlMinutes): array
    {
        return self::build(self::TYPE_PLATFORM, $userId, null, null, $jti, $issuedAt, $ttlMinutes);
    }

    /**
     * The tenant access token's claims: AC-39's set exactly. `tenant` is the
     * tenant's ULID id, never its code - a code is what a client sends and an
     * id is what the master resolved.
     *
     * @return array<string, mixed>
     */
    public static function tenant(string $userId, string $tenantId, string $jti, int $issuedAt, int $ttlMinutes): array
    {
        return self::build(self::TYPE_TENANT, $userId, $tenantId, null, $jti, $issuedAt, $ttlMinutes);
    }

    /**
     * The tenant access token of a membership that names an employee: the
     * short tenant set plus D-2's five, which is AC-56's set exactly.
     *
     * **A third named shape rather than five more parameters.** "Exactly"
     * has to stay a value: {@see self::allowedNames()} builds its specimen
     * through the same private builder, so the set a token carries and the
     * set a verifier admits are the same list computed once. Five
     * parameters on {@see self::tenant()} would make all-or-none a rule
     * each caller remembers, which is the rule {@see EmployeeClaims}
     * exists to keep instead.
     *
     * @return array<string, mixed>
     */
    public static function tenantWithEmployee(
        string $userId,
        string $tenantId,
        EmployeeClaims $employee,
        string $jti,
        int $issuedAt,
        int $ttlMinutes
    ): array {
        return self::build(self::TYPE_TENANT, $userId, $tenantId, $employee, $jti, $issuedAt, $ttlMinutes);
    }

    /**
     * The terminal access token's claims (T-014 AC-33): `sub` is the terminal's
     * id, `tenant` the tenant's ULID, `terminal_id` the same terminal again as
     * the claim a till-specific route reads, and `branch_ids` a list of exactly
     * one upper-case branch id. The plural form keeps one claim shape across
     * token kinds (D-19).
     *
     * @return array<string, mixed>
     */
    public static function terminal(
        string $terminalId,
        string $tenantId,
        string $branchId,
        string $jti,
        int $issuedAt,
        int $ttlMinutes
    ): array {
        return self::build(self::TYPE_TERMINAL, $terminalId, $tenantId, null, $jti, $issuedAt, $ttlMinutes, $branchId);
    }

    /**
     * The claim names one shape is allowed to carry, sorted.
     *
     * @return list<string>
     */
    public static function allowedNames(string $tokenType, bool $withEmployee = false): array
    {
        return self::namesOf(self::build(
            $tokenType,
            'specimen',
            'specimen',
            $withEmployee ? self::specimenEmployee() : null,
            'specimen',
            0,
            0,
            $tokenType === self::TYPE_TERMINAL ? 'specimen' : null
        ));
    }

    /**
     * The claim names of one claim array, sorted - so a comparison is of sets
     * and not of orders.
     *
     * @param  array<string, mixed>  $claims
     * @return list<string>
     */
    public static function namesOf(array $claims): array
    {
        $names = array_map(static fn (int|string $name): string => (string) $name, array_keys($claims));

        sort($names);

        return $names;
    }

    /**
     * Whether a decoded token carries exactly the names its type allows.
     *
     * The tenant shape has two legal forms, with and without `employee`, which
     * is the one optional claim in the design: a membership whose
     * `employee_id` is null yields a token with no `employee` claim at all,
     * never one with a null.
     *
     * @param  array<string, mixed>  $claims
     */
    public static function isExactly(array $claims, string $tokenType): bool
    {
        $names = self::namesOf($claims);

        if ($tokenType === self::TYPE_TENANT) {
            return $names === self::allowedNames($tokenType)
                || $names === self::allowedNames($tokenType, true);
        }

        return $names === self::allowedNames($tokenType);
    }

    /**
     * A claim set of the right shape and of no significance.
     *
     * Only {@see self::allowedNames()} uses it, and only its **keys** are
     * read. The values are the narrowest ones {@see EmployeeClaims} will
     * accept, so the specimen cannot be mistaken for a real employee if it
     * ever escapes: an id of `specimen`, no branches, no rights, version
     * zero and a hash of sixty-four zeroes, which is not the hash of
     * anything.
     */
    private static function specimenEmployee(): EmployeeClaims
    {
        return new EmployeeClaims('specimen', [], false, 0, str_repeat('0', EmployeeClaims::HASH_LENGTH));
    }

    /**
     * The one builder all three shapes and the allowed-name set come from.
     *
     * @return array<string, mixed>
     */
    private static function build(
        string $tokenType,
        string $userId,
        ?string $tenantId,
        ?EmployeeClaims $employee,
        string $jti,
        int $issuedAt,
        int $ttlMinutes,
        ?string $branchId = null
    ): array {
        if (! in_array($tokenType, self::TYPES, true)) {
            throw new InvalidArgumentException(sprintf('unknown token type %s', $tokenType));
        }

        $claims = [
            'sub' => $userId,
            'token_type' => $tokenType,
            'iat' => $issuedAt,
            'exp' => $issuedAt + $ttlMinutes * 60,
            'jti' => $jti,
        ];

        if ($tokenType === self::TYPE_TERMINAL) {
            if ($tenantId === null || $tenantId === '' || $branchId === null || $branchId === '') {
                throw new InvalidArgumentException('a terminal token must name a tenant and a branch');
            }

            // The terminal id is the subject and is repeated as `terminal_id`, so
            // a route reading the terminal does not have to know `sub` means it.
            $claims['tenant'] = $tenantId;
            $claims['terminal_id'] = $userId;
            $claims['branch_ids'] = [$branchId];
        }

        if ($tokenType === self::TYPE_TENANT) {
            if ($tenantId === null || $tenantId === '') {
                throw new InvalidArgumentException('a tenant token must name a tenant');
            }

            $claims['tenant'] = $tenantId;

            if ($employee !== null) {
                // All five or none (AC-58), and the type is what keeps
                // that rule: there is no way to reach this line with some
                // of them.
                $claims = array_merge($claims, $employee->toClaims());
            }
        }

        return $claims;
    }
}
