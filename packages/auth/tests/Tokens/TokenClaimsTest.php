<?php

use Flexipos\Auth\Tokens\TokenClaims;
use Flexipos\Contracts\Access\EmployeeClaims;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-009 step 5, AC-28: "exactly" as a value, not as a literal in three handlers
|--------------------------------------------------------------------------
|
| **Edited by T-010 (FP-208), and the edit is forced by AC-56 rather than
| chosen.** D-10's tenant set was the shorter one - no `branch_ids`, no
| `perm_hash`, no `perm_version` - because those come from the tenant's
| `employees` and `roles` tables and those tables did not exist. T-010
| creates them and AC-56 states the longer set, so a file whose subject is
| *"the exact claim sets"* cannot stay as it was: the cases below that
| named the short tenant set are now about the long one, and the case that
| asserted the five claims were absent asserts that they are present and
| correct. The platform set is **unchanged**, which AC-56 requires in terms
| and which the first case still pins.
|
| What did not change is the property the file exists for: the allowed-name
| set is derived from the minter, never written twice.
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
    $claims = TokenClaims::tenant('01HUSER', '01HTENANT', '01HJTI', 1_760_000_000, 15);

    expect(TokenClaims::namesOf($claims))->toBe(['exp', 'iat', 'jti', 'sub', 'tenant', 'token_type'])
        ->and($claims['tenant'])->toBe('01HTENANT')
        ->and($claims['token_type'])->toBe(TokenClaims::TYPE_TENANT);
});

it('adds the five employee claims together, or none of them (T-010 AC-56, AC-58)', function () {
    $without = TokenClaims::tenant('01HUSER', '01HTENANT', '01HJTI', 1_760_000_000, 15);
    $with = TokenClaims::tenantWithEmployee(
        '01HUSER',
        '01HTENANT',
        new EmployeeClaims('01HEMPLOYEE', ['01HBRANCHA', '01HBRANCHB'], true, 7, str_repeat('b', 64)),
        '01HJTI',
        1_760_000_000,
        15
    );

    foreach (['employee', 'branch_ids', 'all_branches', 'perm_version', 'perm_hash'] as $claim) {
        expect(array_key_exists($claim, $without))->toBeFalse("[{$claim}] must be absent without an employee");
    }

    expect(TokenClaims::namesOf($with))->toBe([
        'all_branches',
        'branch_ids',
        'employee',
        'exp',
        'iat',
        'jti',
        'perm_hash',
        'perm_version',
        'sub',
        'tenant',
        'token_type',
    ])
        ->and($with['employee'])->toBe('01HEMPLOYEE')
        ->and($with['branch_ids'])->toBe(['01HBRANCHA', '01HBRANCHB'])
        ->and($with['all_branches'])->toBeTrue()
        ->and($with['perm_version'])->toBe(7)
        ->and($with['perm_hash'])->toBe(str_repeat('b', 64));
});

it('carries no claim that still belongs to a later task (T-010 D-2)', function () {
    $claims = TokenClaims::tenantWithEmployee(
        '01HUSER',
        '01HTENANT',
        new EmployeeClaims('01HEMPLOYEE', [], false, 1, str_repeat('c', 64)),
        '01HJTI',
        1_760_000_000,
        15
    );

    // `branch` singular and `terminal_id` are FP-209's; the permission
    // list itself is deliberately never in the token, for size and
    // staleness, which is the reason AC-60 exists at all.
    foreach (['branch', 'terminal_id', 'roles', 'permissions', 'employee_id', 'tenant_id'] as $deferred) {
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
        ->toBe(TokenClaims::namesOf(TokenClaims::tenant('u', 't', 'j', 1, 15)))
        ->and(TokenClaims::allowedNames(TokenClaims::TYPE_TENANT, true))
        ->toBe(TokenClaims::namesOf(TokenClaims::tenantWithEmployee(
            'u',
            't',
            new EmployeeClaims('e', [], false, 0, str_repeat('0', 64)),
            'j',
            1,
            15
        )));
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
    $tenant = TokenClaims::tenant('01HUSER', '01HTENANT', '01HJTI', 1_760_000_000, 15);

    expect(TokenClaims::isExactly($platform, TokenClaims::TYPE_TENANT))->toBeFalse()
        ->and(TokenClaims::isExactly($tenant, TokenClaims::TYPE_PLATFORM))->toBeFalse();
});

it('accepts the tenant shape with and without the optional employee claims', function () {
    $withEmployee = TokenClaims::tenantWithEmployee(
        'u',
        't',
        new EmployeeClaims('e', [], false, 2, str_repeat('d', 64)),
        'j',
        1,
        15
    );

    expect(TokenClaims::isExactly(TokenClaims::tenant('u', 't', 'j', 1, 15), TokenClaims::TYPE_TENANT))->toBeTrue()
        ->and(TokenClaims::isExactly($withEmployee, TokenClaims::TYPE_TENANT))->toBeTrue();
});

it('refuses a tenant token carrying employee without the other four (T-010 AC-58)', function () {
    $withEmployee = TokenClaims::tenantWithEmployee(
        'u',
        't',
        new EmployeeClaims('e', [], false, 2, str_repeat('d', 64)),
        'j',
        1,
        15
    );

    // The half-formed set the minter cannot produce, assembled here so
    // that the verifier's answer to it is measured rather than assumed.
    // This is what makes the staleness check's "both claims or no check"
    // a composition rather than a hole: a token carrying `employee` and
    // no `perm_version` never reaches a middleware at all.
    foreach (['branch_ids', 'all_branches', 'perm_version', 'perm_hash'] as $dropped) {
        $half = $withEmployee;
        unset($half[$dropped]);

        expect(TokenClaims::isExactly($half, TokenClaims::TYPE_TENANT))
            ->toBeFalse("a tenant token missing [{$dropped}] must be refused");
    }
});
