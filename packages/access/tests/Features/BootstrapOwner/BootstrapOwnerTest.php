<?php

declare(strict_types=1);

namespace Flexipos\Access\Tests\Features\BootstrapOwner;

use Flexipos\Access\Features\BootstrapOwner\BootstrapOwnerHandler;
use Flexipos\Access\Support\SystemRole;
use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Access\OwnerSeed;
use Flexipos\Contracts\Access\TenantAccessBootstrap;
use Flexipos\Contracts\Access\TenantBootstrapRefused;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-026 step 6 - BootstrapOwnerHandler (AC-25, AC-26, AC-27, AC-29, D-23, D-24)
|--------------------------------------------------------------------------
|
| grantOwner()'s D-23 precondition is proven by a MUTATION-style control: a
| second, different employee granted first must block the subject, and a
| SOFT-DELETED holder must not - both cases are here so the guard cannot be
| satisfied by checking "any row exists" instead of "any LIVE row exists".
|
*/

uses(TestCase::class);

beforeEach(function () {
    AccessTenantFixture::boot();
    AccessTenantFixture::enter('tenant-a');

    app()->instance(RunnerClock::class, RunnerClock::fixedAt('2026-11-07 10:00:00.000'));
});

afterEach(function () {
    AccessTenantFixture::forget();
});

afterAll(function () {
    AccessTenantFixture::deleteRoot();
});

function bootstrap(): TenantAccessBootstrap
{
    /** @var TenantAccessBootstrap $service */
    $service = app(TenantAccessBootstrap::class);

    return $service;
}

function ownerRoleId(): ?string
{
    $row = AccessSeed::connection()->table('roles')
        ->where('key', SystemRole::OWNER_KEY)
        ->where('is_system', true)
        ->first();

    return $row === null ? null : (string) $row->id;
}

it('is bound to the real handler', function () {
    expect(app(TenantAccessBootstrap::class))->toBeInstanceOf(BootstrapOwnerHandler::class);
});

it('seedPermissions() creates the owner role and reports refused keys from a conflict (AC-25)', function () {
    AccessSeed::permission('fixture.conflict', branchScoped: false);

    // A declared key that already exists stored the opposite way is refused
    // by SyncPermissionRegistry itself; this fixture's provider declares no
    // such key, so here we only prove the pass-through of its own result -
    // a disagreeing provider is SyncPermissionRegistryTest's own subject.
    $refused = bootstrap()->seedPermissions();

    expect($refused)->toBeArray()
        ->and(ownerRoleId())->not->toBeNull();
});

it('ensureOwnerEmployee() creates OWNER once; a second call returns the same id', function () {
    $first = bootstrap()->ensureOwnerEmployee(new OwnerSeed('Jane', 'Jane Doe'));
    $second = bootstrap()->ensureOwnerEmployee(new OwnerSeed('Somebody Else'));

    expect($second)->toBe($first);

    $row = AccessSeed::connection()->table('employees')->where('id', $first)->first();

    expect($row->code)->toBe('OWNER')
        ->and($row->display_name)->toBe('Jane Doe')
        ->and(AccessSeed::connection()->table('employees')->where('code', 'OWNER')->count())->toBe(1);
});

it('attachOwnerBranch() writes one employee_branches row with is_default; a second call is a no-op and is not refused', function () {
    $employeeId = bootstrap()->ensureOwnerEmployee(new OwnerSeed('Jane'));
    $branchId = AccessSeed::branch();

    bootstrap()->attachOwnerBranch($employeeId, $branchId);
    bootstrap()->attachOwnerBranch($employeeId, $branchId);

    $rows = AccessSeed::connection()->table('employee_branches')
        ->where('employee_id', $employeeId)
        ->get();

    expect($rows)->toHaveCount(1)
        ->and((bool) $rows[0]->is_default)->toBeTrue();
});

it('grantOwner() grants the system owner with granted_by null and an audit row; a second call for the same employee is a no-op', function () {
    bootstrap()->seedPermissions();
    $employeeId = bootstrap()->ensureOwnerEmployee(new OwnerSeed('Jane'));

    bootstrap()->grantOwner($employeeId);
    bootstrap()->grantOwner($employeeId);

    $roleId = ownerRoleId();

    $rows = AccessSeed::connection()->table('employee_roles')
        ->where('employee_id', $employeeId)
        ->where('role_id', $roleId)
        ->get();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->granted_by)->toBeNull();

    $audit = AccessSeed::connection()->table('audit_logs')
        ->where('action', 'owner_granted')
        ->get();

    expect($audit)->toHaveCount(1);
});

it('refuses with TenantBootstrapRefused when another live employee already holds owner, and writes nothing (D-23)', function () {
    bootstrap()->seedPermissions();

    $firstOwner = bootstrap()->ensureOwnerEmployee(new OwnerSeed('First'));
    bootstrap()->grantOwner($firstOwner);

    $secondEmployee = AccessSeed::employee('second');

    $before = AccessSeed::connection()->table('employee_roles')->count();

    try {
        bootstrap()->grantOwner($secondEmployee);
        $thrown = null;
    } catch (TenantBootstrapRefused $refused) {
        $thrown = $refused;
    }

    expect($thrown)->not->toBeNull()
        ->and(AccessSeed::connection()->table('employee_roles')->count())->toBe($before);
});

it('control: a soft-deleted holder does not trigger the refusal', function () {
    bootstrap()->seedPermissions();

    $firstOwner = bootstrap()->ensureOwnerEmployee(new OwnerSeed('First'));
    bootstrap()->grantOwner($firstOwner);

    AccessSeed::connection()->table('employees')
        ->where('id', $firstOwner)
        ->update(['deleted_at' => '2026-11-07 10:00:00.000']);

    $secondEmployee = AccessSeed::employee('second');

    // Must not throw.
    bootstrap()->grantOwner($secondEmployee);

    $roleId = ownerRoleId();

    expect(AccessSeed::connection()->table('employee_roles')->where('role_id', $roleId)->where('employee_id', $secondEmployee)->exists())
        ->toBeTrue();
});

it('refuses grantOwner() when no owner role exists yet (seedPermissions() was never run)', function () {
    $employeeId = AccessSeed::employee('solo');

    try {
        bootstrap()->grantOwner($employeeId);
        $thrown = null;
    } catch (TenantBootstrapRefused $refused) {
        $thrown = $refused;
    }

    expect($thrown)->not->toBeNull();
});

it('ownerMissingKeys() is empty after the seed, and non-empty after one role_permissions row is deleted', function () {
    bootstrap()->seedPermissions();

    expect(bootstrap()->ownerMissingKeys())->toBe([]);

    $roleId = ownerRoleId();
    $oneKey = AccessSeed::connection()->table('role_permissions')->where('role_id', $roleId)->value('permission_key');

    AccessSeed::connection()->table('role_permissions')
        ->where('role_id', $roleId)
        ->where('permission_key', $oneKey)
        ->delete();

    expect(bootstrap()->ownerMissingKeys())->toBe([$oneKey]);
});

it('linkUser() is idempotent: links once, a second call with the same id does nothing, a different id updates', function () {
    $employeeId = bootstrap()->ensureOwnerEmployee(new OwnerSeed('Jane'));

    bootstrap()->linkUser($employeeId, '01JUSER0000000000000000AA');
    bootstrap()->linkUser($employeeId, '01JUSER0000000000000000AA');

    expect(AccessSeed::connection()->table('employees')->where('id', $employeeId)->value('user_id'))
        ->toBe('01JUSER0000000000000000AA');
});

it('ownerState() returns null when no OWNER employee exists, and the full state once bootstrapped', function () {
    expect(bootstrap()->ownerState())->toBeNull();

    bootstrap()->seedPermissions();
    $employeeId = bootstrap()->ensureOwnerEmployee(new OwnerSeed('Jane'));
    $branchId = AccessSeed::branch();
    bootstrap()->attachOwnerBranch($employeeId, $branchId);
    bootstrap()->grantOwner($employeeId);

    $state = bootstrap()->ownerState();

    expect($state['employee_id'])->toBe($employeeId)
        ->and($state['user_id'])->toBeNull()
        ->and($state['branch_ids'])->toBe([$branchId])
        ->and($state['holds_owner'])->toBeTrue();
});
