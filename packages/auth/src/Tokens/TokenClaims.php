<?php

declare(strict_types=1);

namespace Flexipos\Auth\Tokens;

use InvalidArgumentException;

/**
 * The exact claim sets of D-10 and AC-28, as two named shapes, so that
 * "exactly" is a value and not a literal repeated in three handlers.
 *
 * **The set is the short one (D-10), and that is a refusal rather than an
 * omission.** No `branch_ids`, no `employee` object beyond the
 * `tenant_users.employee_id` passthrough, no `perm_hash` and no
 * `perm_version`: all of those come from the tenant's `employees` and `roles`
 * tables, which do not exist until FP-208. A claim we cannot populate is a
 * claim we must not mint, because FP-208 would then have to change a claim's
 * *meaning* rather than add one, and every token issued in the window would
 * be wrong. `docs/database.md` section 5 and `docs/architecture.md` section 5
 * still print the longer set; FP-200 tracks the annotation and the spec
 * governs.
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
     * The two token types, which are also the two claim shapes.
     */
    public const TYPES = [self::TYPE_PLATFORM, self::TYPE_TENANT];

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
    public static function tenant(string $userId, string $tenantId, ?string $employeeId, string $jti, int $issuedAt, int $ttlMinutes): array
    {
        return self::build(self::TYPE_TENANT, $userId, $tenantId, $employeeId, $jti, $issuedAt, $ttlMinutes);
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
            $withEmployee ? 'specimen' : null,
            'specimen',
            0,
            0
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
     * The one builder both shapes and the allowed-name set come from.
     *
     * @return array<string, mixed>
     */
    private static function build(
        string $tokenType,
        string $userId,
        ?string $tenantId,
        ?string $employeeId,
        string $jti,
        int $issuedAt,
        int $ttlMinutes
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

        if ($tokenType === self::TYPE_TENANT) {
            if ($tenantId === null || $tenantId === '') {
                throw new InvalidArgumentException('a tenant token must name a tenant');
            }

            $claims['tenant'] = $tenantId;

            if ($employeeId !== null) {
                $claims['employee'] = $employeeId;
            }
        }

        return $claims;
    }
}
