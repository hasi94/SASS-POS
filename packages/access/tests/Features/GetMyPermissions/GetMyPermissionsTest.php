<?php

declare(strict_types=1);

namespace Flexipos\Access\Tests\Features\GetMyPermissions;

use Flexipos\Access\Features\GetMyPermissions\GetMyPermissions;
use Flexipos\Access\Features\GetMyPermissions\GetMyPermissionsHandler;
use Flexipos\Access\Features\GetMyPermissions\MyPermissionsView;
use Flexipos\Access\Support\PermHash;
use Flexipos\Access\Support\ScopeKey;
use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-010 step 23 - the caller's own access, read live
|--------------------------------------------------------------------------
|
| AC-60. The HTTP half - the one `authenticated_only` route, and the empty
| answer for a token with no employee claim - is in
| `modules/host/tests/Feature/QaMyPermissionsTest`; what is here is what
| the slice reads and how it answers.
|
| **"Live" is asserted by changing the database between two calls**, not
| by reading the word in a docblock. A handler that answered from
| anything cached would return the first answer twice, and the two calls
| differ in version, hash and contribution list at once.
|
| **The hash is the same function the token's is.** A client's whole use
| of this endpoint is to compare the hash it holds with the current one,
| so the two being computed from one definition is the criterion rather
| than an implementation detail - asserted here by computing the claim
| hash through the shipped helper and requiring the response to equal it.
|
*/

uses(TestCase::class);

beforeEach(function () {
    AccessTenantFixture::boot();
    AccessTenantFixture::enter('tenant-a');
});

afterEach(function () {
    AccessTenantFixture::forget();
});

afterAll(function () {
    AccessTenantFixture::deleteRoot();
});

function myPermissions(?string $employeeId): MyPermissionsView
{
    /** @var GetMyPermissionsHandler $handler */
    $handler = app(GetMyPermissionsHandler::class);

    return $handler->handle(new GetMyPermissions($employeeId));
}

it('answers nothing at all, with no version and no hash, when no employee is named (AC-60)', function () {
    $view = myPermissions(null);

    // Null rather than zero or an empty string: a client compares what
    // it holds with what comes back, and `0` and `''` are values that
    // compare equal to something.
    expect($view->permVersion)->toBeNull()
        ->and($view->permHash)->toBeNull()
        ->and($view->branchIds)->toBe([])
        ->and($view->allBranches)->toBeFalse()
        ->and($view->contributions)->toBe([]);
});

it('returns the contributions with their scope and their allow or deny flag (AC-60)', function () {
    $branchX = AccessSeed::branch();

    AccessSeed::permission('fixture.company');
    AccessSeed::permission('fixture.scoped', true);

    $employeeId = AccessSeed::employee('cashier');

    AccessSeed::attach($employeeId, $branchX);
    AccessSeed::assign($employeeId, AccessSeed::role('fixture_till', ['fixture.scoped']), $branchX);
    AccessSeed::assign($employeeId, AccessSeed::role('fixture_all', ['fixture.company']));
    AccessSeed::personal($employeeId, 'fixture.scoped', false, $branchX);

    $view = myPermissions($employeeId);

    expect($view->contributions)->toBe([
        ['key' => 'fixture.company', 'scope' => ScopeKey::ALL_BRANCHES, 'allowed' => true],
        ['key' => 'fixture.scoped', 'scope' => $branchX, 'allowed' => false],
        ['key' => 'fixture.scoped', 'scope' => $branchX, 'allowed' => true],
    ])
        ->and($view->branchIds)->toBe([$branchX])
        ->and($view->allBranches)->toBeTrue();
});

it('agrees with the hash the token carries, because both come from one definition (AC-54, AC-60)', function () {
    $branchX = AccessSeed::branch();

    AccessSeed::permission('fixture.scoped', true);

    $employeeId = AccessSeed::employee('cashier');
    AccessSeed::attach($employeeId, $branchX);
    AccessSeed::assign($employeeId, AccessSeed::role('fixture_till', ['fixture.scoped']), $branchX);

    $version = AccessSeed::permVersion($employeeId);
    $view = myPermissions($employeeId);

    expect($view->permVersion)->toBe($version)
        ->and($view->permHash)->toBe(PermHash::forEmployee(AccessSeed::connection(), $employeeId, $version));
});

it('reads live, so a grant made between two calls is in the second (AC-60)', function () {
    AccessSeed::permission('fixture.company');

    $employeeId = AccessSeed::employee('cashier');

    $before = myPermissions($employeeId);

    expect($before->contributions)->toBe([]);

    AccessSeed::assign($employeeId, AccessSeed::role('fixture_all', ['fixture.company']));
    AccessSeed::connection()->table('employees')->where('id', $employeeId)->update(['perm_version' => 7]);

    $after = myPermissions($employeeId);

    // Three things moved at once, which is what a cached answer could
    // not produce and what a stale one would show none of.
    expect($after->contributions)->toBe([
        ['key' => 'fixture.company', 'scope' => ScopeKey::ALL_BRANCHES, 'allowed' => true],
    ])
        ->and($after->permVersion)->toBe(7)
        ->and($after->permHash)->not->toBe($before->permHash)
        ->and($after->allBranches)->toBeTrue()
        ->and($before->allBranches)->toBeFalse();
});

it('reads nothing about any other employee (AC-65)', function () {
    AccessSeed::permission('fixture.company');

    $mine = AccessSeed::employee('mine');
    $theirs = AccessSeed::employee('theirs');

    AccessSeed::assign($theirs, AccessSeed::role('fixture_all', ['fixture.company']));
    AccessSeed::connection()->table('employees')->where('id', $theirs)->update(['perm_version' => 42]);

    $view = myPermissions($mine);

    expect($view->contributions)->toBe([])
        ->and($view->permVersion)->not->toBe(42);
});

it('answers nothing for an employee the database no longer serves (AC-60, D-7)', function () {
    $employeeId = AccessSeed::employee('gone', ['deleted_at' => '2026-10-20 09:00:00.000']);

    // Unreachable over HTTP - the staleness check refuses such a token
    // first (AC-66) - so this pins the fail-closed answer rather than a
    // behaviour a user can produce.
    expect(myPermissions($employeeId)->permVersion)->toBeNull();
});
