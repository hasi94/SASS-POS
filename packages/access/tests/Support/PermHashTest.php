<?php

declare(strict_types=1);

namespace Flexipos\Access\Tests\Support;

use Flexipos\Access\Support\PermHash;
use Flexipos\Access\Support\ScopeKey;
use Flexipos\Contracts\Access\EmployeeClaims;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-010 step 8 - the canonical form of perm_hash
|--------------------------------------------------------------------------
|
| AC-54's properties and AC-55, over the function itself. No database: the
| row-level half - the same contributions arriving as differently ordered
| rows with different ids - is PermHashRowsTest, against the fixture tenant.
|
| **The format is pinned by a vector this file builds itself**, character by
| character, from the plan's Ruling 6 description rather than by calling the
| class. The till has to reproduce this hash from that description, so a test
| that computed the expectation the way the implementation does would agree
| with any format at all, including one the description does not describe.
|
| **The overclaim is named and not made (D-23).** "Equal hash iff equal
| rights" is false here and the last case asserts that it is false: an allow
| reached through a role and the same allow granted directly resolve the same
| and hash differently. Writing that down is what stops a later reader
| treating the hash as an authorization comparison.
|
*/

uses(TestCase::class);

/**
 * @param  list<array{bool, string, string}>  $contributions
 */
function permHash(int $version, array $contributions): string
{
    return PermHash::compute($version, $contributions);
}

function permBranch(string $stem): string
{
    return str_pad(strtoupper($stem), 26, '0');
}

/*
|--------------------------------------------------------------------------
| The published format
|--------------------------------------------------------------------------
*/

it('produces exactly the hash the published canonical form describes', function () {
    $branch = permBranch('BRANCH-A');

    // Assembled here from Ruling 6's description, not from the class:
    // tag, newline, unpadded decimal version, newline, then the lines
    // byte-wise ascending and joined with newlines. "allow" sorts before
    // "deny", so the role-derived line comes first whatever order it was
    // handed in.
    $expectedInput = "flexipos.perm.v1\n7\n"
        ."allow\tb.second\t".ScopeKey::ALL_BRANCHES."\n"
        ."deny\ta.first\t".$branch;

    $expected = bin2hex(hash('sha256', $expectedInput, true));

    expect(permHash(7, [
        [false, 'a.first', $branch],
        [true, 'b.second', ScopeKey::ALL_BRANCHES],
    ]))->toBe($expected)
        ->and(strlen($expected))->toBe(EmployeeClaims::HASH_LENGTH)
        ->and(preg_match('/^[0-9a-f]{64}$/', $expected))->toBe(1);
});

it('writes all branches as the marker and never as an empty scope', function () {
    // The two renderings a careless implementation confuses. If null became
    // an empty string, these two would hash alike and a contribution
    // covering every branch would be indistinguishable from one covering a
    // branch whose id is empty.
    expect(permHash(1, [[true, 'a.key', ScopeKey::of(null)]]))
        ->toBe(permHash(1, [[true, 'a.key', '*']]))
        ->and(permHash(1, [[true, 'a.key', ScopeKey::of(null)]]))
        ->not->toBe(permHash(1, [[true, 'a.key', '']]));
});

/*
|--------------------------------------------------------------------------
| AC-54 - what must not change the hash
|--------------------------------------------------------------------------
*/

it('is independent of the order the contributions arrive in', function () {
    $branch = permBranch('BRANCH-A');

    $one = [
        [true, 'a.first', ScopeKey::ALL_BRANCHES],
        [false, 'b.second', $branch],
        [true, 'c.third', $branch],
    ];

    expect(permHash(3, $one))->toBe(permHash(3, array_reverse($one)))
        ->and(permHash(3, $one))->toBe(permHash(3, [$one[2], $one[0], $one[1]]));
});

it('collapses a contribution handed in twice', function () {
    $line = [true, 'a.first', ScopeKey::ALL_BRANCHES];

    expect(permHash(3, [$line]))->toBe(permHash(3, [$line, $line]))
        ->and(permHash(3, [$line]))->toBe(permHash(3, [$line, $line, $line]));
});

it('gives two employees with identical contributions and versions the same hash', function () {
    $contributions = [[true, 'a.first', ScopeKey::ALL_BRANCHES], [false, 'b.second', permBranch('BRANCH-A')]];

    expect(permHash(9, $contributions))->toBe(permHash(9, $contributions));
});

/*
|--------------------------------------------------------------------------
| AC-54 - what must change it
|--------------------------------------------------------------------------
*/

it('changes when any single thing about the contributions changes', function () {
    $branch = permBranch('BRANCH-A');
    $other = permBranch('BRANCH-B');

    $base = [[true, 'a.first', ScopeKey::ALL_BRANCHES], [false, 'b.second', $branch]];
    $hash = permHash(5, $base);

    expect(permHash(5, [[false, 'a.first', ScopeKey::ALL_BRANCHES], [false, 'b.second', $branch]]))
        ->not->toBe($hash)
        ->and(permHash(5, [[true, 'a.first', ScopeKey::ALL_BRANCHES]]))->not->toBe($hash)
        ->and(permHash(5, array_merge($base, [[true, 'c.third', $branch]])))->not->toBe($hash)
        ->and(permHash(5, [[true, 'a.first', ScopeKey::ALL_BRANCHES], [false, 'b.second', $other]]))
        ->not->toBe($hash)
        // The version is inside the input, so a bump alone moves the hash -
        // which is the cheap signal a client watches.
        ->and(permHash(6, $base))->not->toBe($hash);
});

/*
|--------------------------------------------------------------------------
| AC-55 - an employee with nothing
|--------------------------------------------------------------------------
*/

it('still defines a hash for an employee with no contributions', function () {
    $empty = permHash(1, []);

    expect(preg_match('/^[0-9a-f]{64}$/', $empty))->toBe(1)
        // Defined, and still a function of the version: two employees with
        // nothing but different versions are not the same employee.
        ->and(permHash(2, []))->not->toBe($empty);
});

/*
|--------------------------------------------------------------------------
| D-23 - the property that is deliberately NOT claimed
|--------------------------------------------------------------------------
*/

it('does not promise that two configurations resolving alike hash alike', function () {
    // Both of these let the employee do a.first everywhere. One reaches it
    // through a role, the other grants it directly, and AC-54 hashes
    // CONTRIBUTIONS, so the hashes differ. Asserted rather than described,
    // because the overclaim - equal hash iff equal rights - is the one a
    // later reader makes by accident, and a claim nobody wrote down is not
    // one a test can refuse.
    $throughRole = permHash(4, [[true, 'a.first', ScopeKey::ALL_BRANCHES]]);
    $plusDirect = permHash(4, [
        [true, 'a.first', ScopeKey::ALL_BRANCHES],
        [true, 'a.first', permBranch('BRANCH-A')],
    ]);

    expect($plusDirect)->not->toBe($throughRole);
});
