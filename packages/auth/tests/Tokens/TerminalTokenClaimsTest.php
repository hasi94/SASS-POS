<?php

use Flexipos\Auth\Tokens\TokenClaims;
use Flexipos\Contracts\Access\EmployeeClaims;

/*
|--------------------------------------------------------------------------
| T-014 step 11 - the terminal token's claim set (AC-33, D-19, D-42)
|--------------------------------------------------------------------------
|
| Set equality in both directions, as `TokenClaimsTest` does for the other two
| shapes: an extra claim fails, and so does a missing one. The five employee
| claims are named one by one as absent, because a terminal is a machine and a
| token carrying `employee` would be read as a person. The tenant shape, with
| and without its five, is asserted unchanged beside it: adding a type must
| not have moved the two that existed.
*/

it('carries tenant, terminal_id and exactly one branch, and nothing of an employee (AC-33)', function () {
    $claims = TokenClaims::terminal('TERMINAL', 'TENANTID', 'BRANCHID', 'jti', 1000, 15);

    $names = TokenClaims::namesOf($claims);

    expect($names)->toBe(['branch_ids', 'exp', 'iat', 'jti', 'sub', 'tenant', 'terminal_id', 'token_type'])
        ->and($claims['sub'])->toBe('TERMINAL')
        ->and($claims['terminal_id'])->toBe('TERMINAL')
        ->and($claims['tenant'])->toBe('TENANTID')
        ->and($claims['token_type'])->toBe(TokenClaims::TYPE_TERMINAL)
        ->and($claims['branch_ids'])->toBe(['BRANCHID'])
        ->and($claims['exp'])->toBe(1000 + 15 * 60);

    foreach (['employee', 'all_branches', 'perm_version', 'perm_hash'] as $absent) {
        expect(in_array($absent, $names, true))->toBeFalse("[{$absent}] must not be a terminal claim");
    }
});

it('derives the allowed names from the minting shape, so the two cannot drift (AC-33)', function () {
    $claims = TokenClaims::terminal('T', 'X', 'B', 'j', 1, 15);

    expect(TokenClaims::allowedNames(TokenClaims::TYPE_TERMINAL))->toBe(TokenClaims::namesOf($claims))
        ->and(TokenClaims::isExactly($claims, TokenClaims::TYPE_TERMINAL))->toBeTrue();
});

it('refuses a terminal token with an extra claim, a missing one, or an employee claim by name (AC-33)', function () {
    $claims = TokenClaims::terminal('T', 'X', 'B', 'j', 1, 15);

    foreach (['employee' => 'E', 'all_branches' => false, 'perm_version' => 1, 'perm_hash' => 'h', 'database' => 'x'] as $extra => $value) {
        expect(TokenClaims::isExactly($claims + [$extra => $value], TokenClaims::TYPE_TERMINAL))->toBeFalse("[{$extra}] was admitted");
    }

    foreach (array_keys($claims) as $name) {
        $short = $claims;
        unset($short[$name]);

        expect(TokenClaims::isExactly($short, TokenClaims::TYPE_TERMINAL))->toBeFalse("a token without [{$name}] was admitted");
    }
});

it('is not a tenant token and not a platform token, in either direction (AC-35)', function () {
    $terminal = TokenClaims::terminal('T', 'X', 'B', 'j', 1, 15);
    $tenant = TokenClaims::tenant('U', 'X', 'j', 1, 15);
    $platform = TokenClaims::platform('U', 'j', 1, 15);

    expect(TokenClaims::isExactly($terminal, TokenClaims::TYPE_TENANT))->toBeFalse()
        ->and(TokenClaims::isExactly($terminal, TokenClaims::TYPE_PLATFORM))->toBeFalse()
        ->and(TokenClaims::isExactly($tenant, TokenClaims::TYPE_TERMINAL))->toBeFalse()
        ->and(TokenClaims::isExactly($platform, TokenClaims::TYPE_TERMINAL))->toBeFalse();
});

it('leaves the tenant shape, with and without its five employee claims, exactly as it was (AC-33 control)', function () {
    $employee = new EmployeeClaims('E', ['B'], false, 3, str_repeat('a', EmployeeClaims::HASH_LENGTH));
    $with = TokenClaims::tenantWithEmployee('U', 'X', $employee, 'j', 1, 15);
    $without = TokenClaims::tenant('U', 'X', 'j', 1, 15);

    expect(TokenClaims::namesOf($with))->toBe(['all_branches', 'branch_ids', 'employee', 'exp', 'iat', 'jti', 'perm_hash', 'perm_version', 'sub', 'tenant', 'token_type'])
        ->and(TokenClaims::namesOf($without))->toBe(['exp', 'iat', 'jti', 'sub', 'tenant', 'token_type'])
        ->and(TokenClaims::isExactly($with, TokenClaims::TYPE_TENANT))->toBeTrue()
        ->and(TokenClaims::isExactly($without, TokenClaims::TYPE_TENANT))->toBeTrue();
});

it('will not build a terminal token without a tenant or a branch', function () {
    expect(fn () => TokenClaims::terminal('T', '', 'B', 'j', 1, 15))->toThrow(InvalidArgumentException::class)
        ->and(fn () => TokenClaims::terminal('T', 'X', '', 'j', 1, 15))->toThrow(InvalidArgumentException::class);
});
