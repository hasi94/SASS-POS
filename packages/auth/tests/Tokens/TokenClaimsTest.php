<?php

use Flexipos\Auth\Tokens\TokenClaims;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-009 step 5, AC-28: "exactly" as a value, not as a literal in three handlers
|--------------------------------------------------------------------------
|
| D-10's claim set is the **shorter** one: no `branch_ids`, no `employee`
| object beyond the `tenant_users.employee_id` passthrough, no
| `perm_hash`/`perm_version`. Those come from the tenant's `employees` and
| `roles` tables, which do not exist until FP-208, and minting a claim we
| cannot populate is the thing D-10 forbids - FP-208 would then have to change
| a claim's *meaning* rather than add one, and every token issued in the
| meantime would be wrong. `docs/database.md` section 5 and
| `docs/architecture.md` section 5 still print the longer set; FP-200 tracks
| them and **the spec wins**.
|
| **The allowed-name set is derived from the minting shape, never written
| twice.** A second literal list is the drift that AC-28 is asserted against:
| it would agree with the minter on the day it was written and disagree the
| day a claim was added. So `allowedNames()` builds a specimen through the same
| private builder the real claims come from and returns its keys, and the test
| below proves the two move together by comparing a minted token's names with
| it rather than with a list written here.
|
| **Set equality, not containment.** That is the whole point of the criterion:
| a token carrying an extra `database` claim must fail, and so must one missing
| `jti`. Both directions are asserted, because a "contains" comparison passes
| the first and a "subset" comparison passes the second.
|
| No database, no keys, no clock: these are pure values.
|
*/

uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| The two shapes
|--------------------------------------------------------------------------
*/

it('mints the platform claim set of D-10 and nothing else', function () {
    $claims = TokenClaims::platform('01HUSER', '01HJTI', 1_760_000_000, 15);

    expect(TokenClaims::namesOf($claims))->toBe(['exp', 'iat', 'jti', 'sub', 'token_type'])
        ->and($claims['sub'])->toBe('01HUSER')
        ->and($claims['token_type'])->toBe(TokenClaims::TYPE_PLATFORM)
        ->and($claims['jti'])->toBe('01HJTI')
        ->and($claims['iat'])->toBe(1_760_000_000)
        ->and($claims['exp'])->toBe(1_760_000_000 + 15 * 60);
});

it('mints the tenant claim set with the tenant id, never the code', function () {
    $claims = TokenClaims::tenant('01HUSER', '01HTENANT', null, '01HJTI', 1_760_000_000, 15);

    expect(TokenClaims::namesOf($claims))->toBe(['exp', 'iat', 'jti', 'sub', 'tenant', 'token_type'])
        ->and($claims['tenant'])->toBe('01HTENANT')
        ->and($claims['token_type'])->toBe(TokenClaims::TYPE_TENANT);
});

it('adds the employee claim only when the membership carries one', function () {
    $without = TokenClaims::tenant('01HUSER', '01HTENANT', null, '01HJTI', 1_760_000_000, 15);
    $with = TokenClaims::tenant('01HUSER', '01HTENANT', '01HEMPLOYEE', '01HJTI', 1_760_000_000, 15);

    expect(array_key_exists('employee', $without))->toBeFalse()
        ->and($with['employee'])->toBe('01HEMPLOYEE')
        ->and(TokenClaims::namesOf($with))->toBe(['employee', 'exp', 'iat', 'jti', 'sub', 'tenant', 'token_type']);
});

it('carries no claim that FP-208 owns', function () {
    $claims = TokenClaims::tenant('01HUSER', '01HTENANT', '01HEMPLOYEE', '01HJTI', 1_760_000_000, 15);

    foreach (['branch_ids', 'branch', 'perm_hash', 'perm_version', 'roles', 'permissions'] as $deferred) {
        expect(array_key_exists($deferred, $claims))->toBeFalse();
    }
});

/*
|--------------------------------------------------------------------------
| The allowed set follows the minter
|--------------------------------------------------------------------------
*/

it('derives the allowed names from the minting shape rather than from a second list', function () {
    // Nothing here writes the names out: both sides come from the class, and a
    // claim added to one shape without the other makes this red.
    expect(TokenClaims::allowedNames(TokenClaims::TYPE_PLATFORM))
        ->toBe(TokenClaims::namesOf(TokenClaims::platform('u', 'j', 1, 15)))
        ->and(TokenClaims::allowedNames(TokenClaims::TYPE_TENANT))
        ->toBe(TokenClaims::namesOf(TokenClaims::tenant('u', 't', null, 'j', 1, 15)))
        ->and(TokenClaims::allowedNames(TokenClaims::TYPE_TENANT, true))
        ->toBe(TokenClaims::namesOf(TokenClaims::tenant('u', 't', 'e', 'j', 1, 15)));
});

/*
|--------------------------------------------------------------------------
| Set equality, both directions
|--------------------------------------------------------------------------
*/

it('accepts a minted set whatever order its names arrive in', function () {
    $claims = TokenClaims::platform('01HUSER', '01HJTI', 1_760_000_000, 15);
    $shuffled = array_reverse($claims, true);

    expect(TokenClaims::namesOf($shuffled))->toBe(TokenClaims::namesOf($claims))
        ->and(TokenClaims::isExactly($shuffled, TokenClaims::TYPE_PLATFORM))->toBeTrue();
});

it('refuses one extra claim - the negative control AC-28 names', function () {
    $claims = TokenClaims::platform('01HUSER', '01HJTI', 1_760_000_000, 15);

    expect(TokenClaims::isExactly($claims, TokenClaims::TYPE_PLATFORM))->toBeTrue();

    foreach (['database', 'host', 'port', 'perm_hash'] as $extra) {
        expect(TokenClaims::isExactly($claims + [$extra => 'x'], TokenClaims::TYPE_PLATFORM))->toBeFalse();
    }
});

it('refuses a missing claim too, which a subset comparison would let through', function () {
    $claims = TokenClaims::platform('01HUSER', '01HJTI', 1_760_000_000, 15);

    foreach (['jti', 'exp', 'iat', 'sub', 'token_type'] as $dropped) {
        $short = $claims;
        unset($short[$dropped]);

        expect(TokenClaims::isExactly($short, TokenClaims::TYPE_PLATFORM))->toBeFalse();
    }
});

it('refuses each shape against the other type, so the two sets cannot be confused', function () {
    $platform = TokenClaims::platform('01HUSER', '01HJTI', 1_760_000_000, 15);
    $tenant = TokenClaims::tenant('01HUSER', '01HTENANT', null, '01HJTI', 1_760_000_000, 15);

    expect(TokenClaims::isExactly($platform, TokenClaims::TYPE_TENANT))->toBeFalse()
        ->and(TokenClaims::isExactly($tenant, TokenClaims::TYPE_PLATFORM))->toBeFalse();
});

it('accepts the tenant shape with and without the optional employee claim', function () {
    expect(TokenClaims::isExactly(TokenClaims::tenant('u', 't', null, 'j', 1, 15), TokenClaims::TYPE_TENANT))->toBeTrue()
        ->and(TokenClaims::isExactly(TokenClaims::tenant('u', 't', 'e', 'j', 1, 15), TokenClaims::TYPE_TENANT))->toBeTrue();
});
