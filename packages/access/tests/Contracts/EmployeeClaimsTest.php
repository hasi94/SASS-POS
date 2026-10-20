<?php

declare(strict_types=1);

namespace Flexipos\Access\Tests\Contracts;

use Flexipos\Contracts\Access\EmployeeClaims;
use InvalidArgumentException;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-010 step 1, D-2 - all-or-none as a property of the type
|--------------------------------------------------------------------------
|
| AC-58 requires the five employee-derived claims to be minted together or not
| at all. Asserting that at each minting site would be one assertion per site
| and a rule somebody has to remember at the next one; asserting it here is
| the same rule held by the constructor, so a half-formed claim set has no
| representation to be minted from.
|
| The hash length is asserted as a *refusal*, not as a shape. A truncated hash
| is the plausible defect - it fits in a column, it looks like a hash - and
| Ruling 6 says 64 lower-case hexadecimal characters, never truncated.
|
| `branchIds` is asserted to be rejected unsorted and rejected with
| duplicates, rather than silently normalised: the canonical form is what the
| hash is computed over, so a caller handing a different order must find out
| here and not produce a second hash for one set of rights.
|
*/

uses(TestCase::class);

/**
 * A hash-shaped value: 64 lower-case hexadecimal characters.
 */
function claimsHash(string $seed): string
{
    return hash('sha256', $seed);
}

it('carries the five claims under their wire names', function () {
    $claims = new EmployeeClaims('01HEMP', ['01HBRANCHA', '01HBRANCHB'], false, 7, claimsHash('a'));

    expect($claims->toClaims())->toBe([
        'employee' => '01HEMP',
        'branch_ids' => ['01HBRANCHA', '01HBRANCHB'],
        'all_branches' => false,
        'perm_version' => 7,
        'perm_hash' => claimsHash('a'),
    ])->and(array_keys($claims->toClaims()))
        ->toBe(['employee', 'branch_ids', 'all_branches', 'perm_version', 'perm_hash']);
});

it('accepts an employee attached to no branch at all', function () {
    $claims = new EmployeeClaims('01HEMP', [], true, 0, claimsHash('b'));

    expect($claims->branchIds)->toBe([])
        ->and($claims->allBranches)->toBeTrue()
        ->and($claims->permVersion)->toBe(0);
});

it('refuses claims with no employee id', function () {
    expect(static fn () => new EmployeeClaims('', [], false, 1, claimsHash('c')))
        ->toThrow(InvalidArgumentException::class);
});

it('refuses branch ids that are not distinct and sorted', function () {
    expect(static fn () => new EmployeeClaims('01HEMP', ['01HB', '01HA'], false, 1, claimsHash('d')))
        ->toThrow(InvalidArgumentException::class)
        ->and(static fn () => new EmployeeClaims('01HEMP', ['01HA', '01HA'], false, 1, claimsHash('e')))
        ->toThrow(InvalidArgumentException::class);
});

it('refuses a negative permission version', function () {
    expect(static fn () => new EmployeeClaims('01HEMP', [], false, -1, claimsHash('f')))
        ->toThrow(InvalidArgumentException::class);
});

it('refuses a truncated, upper-case or non-hexadecimal hash', function () {
    $full = claimsHash('g');

    expect(static fn () => new EmployeeClaims('01HEMP', [], false, 1, substr($full, 0, 32)))
        ->toThrow(InvalidArgumentException::class)
        ->and(static fn () => new EmployeeClaims('01HEMP', [], false, 1, strtoupper($full)))
        ->toThrow(InvalidArgumentException::class)
        ->and(static fn () => new EmployeeClaims('01HEMP', [], false, 1, str_repeat('z', 64)))
        ->toThrow(InvalidArgumentException::class);
});
