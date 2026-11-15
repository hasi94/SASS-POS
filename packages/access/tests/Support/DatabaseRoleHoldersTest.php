<?php

use Flexipos\Access\Support\DatabaseRoleHolders;
use Flexipos\Access\Support\EmployeeStatus;
use Flexipos\Access\Support\TenantConnection;
use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Tests\TestCase;

uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| DatabaseRoleHolders (T-020 Ruling 6a, step 4)
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    AccessTenantFixture::boot();
    AccessTenantFixture::enter();
});

afterEach(function () {
    AccessTenantFixture::forget();
});

function roleHolders(): DatabaseRoleHolders
{
    return new DatabaseRoleHolders(app(TenantConnection::class), app(EmployeeStatus::class));
}

it('resolves the holders of one role and of two roles, the smallest qualifying role id first', function () {
    $e1 = AccessSeed::employee('e1');
    $e2 = AccessSeed::employee('e2');
    $roleA = AccessSeed::role('role_a');
    $roleB = AccessSeed::role('role_b');

    AccessSeed::assign($e1, $roleA);
    AccessSeed::assign($e2, $roleB);

    $single = roleHolders()->holdersOf([$roleA], null);

    expect($single)->toBe([$e1 => $roleA]);

    $both = roleHolders()->holdersOf([$roleA, $roleB], null);

    expect($both)->toHaveKeys([$e1, $e2]);

    // The qualifying role is the smallest matching role id when an
    // employee holds more than one of the asked-for roles.
    AccessSeed::assign($e1, $roleB);
    $smallest = min($roleA, $roleB);

    expect(roleHolders()->holdersOf([$roleA, $roleB], null)[$e1])->toBe($smallest);
});

it('counts a branch-specific assignment only for its own branch, and an all-branch one for every branch', function () {
    $branchA = AccessSeed::branch();
    $branchB = AccessSeed::branch();
    $role = AccessSeed::role('scoped');

    $scoped = AccessSeed::employee('scoped-emp');
    AccessSeed::assign($scoped, $role, $branchA);

    $allBranch = AccessSeed::employee('all-branch-emp');
    AccessSeed::assign($allBranch, $role, null);

    expect(roleHolders()->holdersOf([$role], $branchA))->toHaveKeys([$scoped, $allBranch])
        ->and(roleHolders()->holdersOf([$role], $branchB))->toHaveKey($allBranch)
        ->and(roleHolders()->holdersOf([$role], $branchB))->not->toHaveKey($scoped);
});

it('counts only all-branch holders when the instance has no branch', function () {
    $branchA = AccessSeed::branch();
    $role = AccessSeed::role('scoped2');

    $scoped = AccessSeed::employee('scoped-emp2');
    AccessSeed::assign($scoped, $role, $branchA);

    $allBranch = AccessSeed::employee('all-branch-emp2');
    AccessSeed::assign($allBranch, $role, null);

    $holders = roleHolders()->holdersOf([$role], null);

    expect($holders)->toHaveKey($allBranch)
        ->and($holders)->not->toHaveKey($scoped);
});

it('excludes a suspended employee and a soft-deleted employee, and includes a probationary one (control)', function () {
    $role = AccessSeed::role('status_role');

    $active = AccessSeed::employee('active-emp');
    $suspended = AccessSeed::employee('suspended-emp', ['status' => 'suspended']);
    $probation = AccessSeed::employee('probation-emp', ['status' => 'probation']);
    $deleted = AccessSeed::employee('deleted-emp');

    AccessSeed::assign($active, $role);
    AccessSeed::assign($suspended, $role);
    AccessSeed::assign($probation, $role);
    AccessSeed::assign($deleted, $role);

    AccessSeed::connection()->table('employees')->where('id', $deleted)->update(['deleted_at' => now()->format('Y-m-d H:i:s.v')]);

    $holders = roleHolders()->holdersOf([$role], null);

    expect($holders)->toHaveKeys([$active, $probation])
        ->and($holders)->not->toHaveKey($suspended)
        ->and($holders)->not->toHaveKey($deleted);
});

it('filters actingAmong the same way', function () {
    $active = AccessSeed::employee('acting-active');
    $suspended = AccessSeed::employee('acting-suspended', ['status' => 'suspended']);

    expect(roleHolders()->actingAmong([$active, $suspended]))->toBe([$active]);
});

it('reads the acting set through the policy rather than a copy, moved-value proof', function () {
    $role = AccessSeed::role('moved_value_role');
    $probation = AccessSeed::employee('moved-value-probation', ['status' => 'probation']);

    AccessSeed::assign($probation, $role);

    expect(roleHolders()->holdersOf([$role], null))->toHaveKey($probation);

    $narrowed = new DatabaseRoleHolders(app(TenantConnection::class), EmployeeStatus::withActingStatuses(['active']));

    expect($narrowed->holdersOf([$role], null))->not->toHaveKey($probation);
});
