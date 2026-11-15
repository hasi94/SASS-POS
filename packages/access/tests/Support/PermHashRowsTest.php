<?php

declare(strict_types=1);

namespace Flexipos\Access\Tests\Support;

use Flexipos\Access\Support\PermHash;
use Flexipos\Access\Support\ScopeKey;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-010 step 8 - perm_hash over real rows
|--------------------------------------------------------------------------
|
| AC-54's row-level properties: the same contributions arriving as rows in a
| different order, with different ids, created in a different order, give the
| same hash; flipping one `allowed` flag changes it; two employees with
| identical contributions hash identically; an employee with none still has a
| hash (AC-55).
|
| This is the half PermHashTest cannot answer. That file proves the function;
| this one proves that what the function is handed is the employee's
| contributions and nothing about how they are stored - which is where a join
| picking up the wrong scope, or ordering leaking in through the query, would
| show.
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

function rowsTenant(): Connection
{
    /** @var Connection $connection */
    $connection = DB::connection(AccessTenantFixture::connectionName());

    return $connection;
}

function rowsId(string $stem): string
{
    return str_pad(strtoupper($stem), 26, '0');
}

function rowsEmployee(string $stem, int $permVersion = 1): string
{
    $id = rowsId($stem);

    rowsTenant()->table('employees')->insert([
        'id' => $id,
        'code' => $stem,
        'first_name' => 'Test',
        'display_name' => 'Test',
        'status' => 'active',
        'perm_version' => $permVersion,
        'created_at' => '2026-10-20 00:00:00.000',
        'updated_at' => '2026-10-20 00:00:00.000',
    ]);

    return $id;
}

function rowsPermission(string $key): void
{
    rowsTenant()->table('permissions')->insertOrIgnore([
        'key' => $key,
        'module' => 'fixture',
        'group' => 'fixture',
        'description' => 'A fixture key.',
        'is_branch_scoped' => false,
    ]);
}

/**
 * A role holding these keys.
 *
 * @param  list<string>  $keys
 */
function rowsRole(string $stem, array $keys): string
{
    $id = rowsId($stem);

    rowsTenant()->table('roles')->insert([
        'id' => $id,
        'key' => strtolower($stem),
        'name' => $stem,
        'is_system' => false,
        'created_at' => '2026-10-20 00:00:00.000',
        'updated_at' => '2026-10-20 00:00:00.000',
    ]);

    foreach ($keys as $key) {
        rowsPermission($key);

        rowsTenant()->table('role_permissions')->insert([
            'role_id' => $id,
            'permission_key' => $key,
        ]);
    }

    return $id;
}

/**
 * Make sure a branch row holds this id (T-014 step 6).
 *
 * The contract migration keys every branch column onto `branches`, so a row
 * naming an opaque id needs the branch it names. Seeding only: no assertion
 * in this file changes.
 */
function rowsEnsureBranch(?string $branchId): void
{
    if ($branchId === null || rowsTenant()->table('branches')->where('id', $branchId)->exists()) {
        return;
    }

    rowsTenant()->table('branches')->insert([
        'id' => $branchId,
        'code' => 'B'.substr(md5($branchId), 0, 8),
        'name' => 'Seeded '.$branchId,
        'timezone' => 'Asia/Colombo',
        'currency' => 'LKR',
        'created_at' => '2026-10-20 00:00:00.000',
        'updated_at' => '2026-10-20 00:00:00.000',
    ]);
}

function rowsAssign(string $assignmentId, string $employeeId, string $roleId, ?string $branchId = null): void
{
    rowsEnsureBranch($branchId);

    rowsTenant()->table('employee_roles')->insert([
        'id' => rowsId($assignmentId),
        'employee_id' => $employeeId,
        'role_id' => $roleId,
        'branch_id' => $branchId,
        'granted_at' => '2026-10-20 00:00:00.000',
    ]);
}

function rowsGrant(string $grantId, string $employeeId, string $key, ?string $branchId, bool $allowed): void
{
    rowsPermission($key);
    rowsEnsureBranch($branchId);

    rowsTenant()->table('employee_permissions')->insert([
        'id' => rowsId($grantId),
        'employee_id' => $employeeId,
        'permission_key' => $key,
        'branch_id' => $branchId,
        'allowed' => $allowed,
    ]);
}

function rowsHash(string $employeeId, int $permVersion = 1): string
{
    return PermHash::forEmployee(rowsTenant(), $employeeId, $permVersion);
}

/*
|--------------------------------------------------------------------------
| AC-54 - the contributions, read from both sources
|--------------------------------------------------------------------------
*/

it('reads a role-derived allow at the scope the assignment carries', function () {
    $branch = rowsId('BRANCH-A');
    $employee = rowsEmployee('hash-one');
    $role = rowsRole('ROLE-A', ['a.first', 'b.second']);

    rowsAssign('ASSIGN-1', $employee, $role, $branch);
    rowsGrant('GRANT-1', $employee, 'c.third', null, false);

    // The expectation is built from the function, not from the query: if
    // the join took the role's own scope - there is none - or dropped the
    // branch, these would differ.
    expect(rowsHash($employee, 2))->toBe(PermHash::compute(2, [
        [true, 'a.first', $branch],
        [true, 'b.second', $branch],
        [false, 'c.third', ScopeKey::ALL_BRANCHES],
    ]));
});

it('gives two employees with identical contributions the same hash', function () {
    $branch = rowsId('BRANCH-A');
    $role = rowsRole('ROLE-A', ['a.first']);

    $one = rowsEmployee('hash-one');
    $two = rowsEmployee('hash-two');

    // Deliberately built in the opposite order, with different row ids and
    // different creation order, which is AC-54's control.
    rowsAssign('ASSIGN-1', $one, $role, $branch);
    rowsGrant('GRANT-1', $one, 'c.third', null, false);

    rowsGrant('GRANT-2', $two, 'c.third', null, false);
    rowsAssign('ASSIGN-2', $two, $role, $branch);

    expect(rowsHash($one, 4))->toBe(rowsHash($two, 4))
        // Liveness: the two hashes being equal would also be what a
        // function ignoring its input produces.
        ->and(rowsHash($one, 4))->not->toBe(rowsHash($one, 5));
});

it('is unchanged when an employee rows are rebuilt in another order with new ids', function () {
    $branch = rowsId('BRANCH-A');
    $employee = rowsEmployee('hash-one');
    $role = rowsRole('ROLE-A', ['a.first']);

    rowsAssign('ASSIGN-1', $employee, $role, $branch);
    rowsGrant('GRANT-1', $employee, 'c.third', null, true);
    rowsGrant('GRANT-2', $employee, 'd.fourth', $branch, false);

    $before = rowsHash($employee, 3);

    rowsTenant()->table('employee_permissions')->where('employee_id', $employee)->delete();
    rowsTenant()->table('employee_roles')->where('employee_id', $employee)->delete();

    rowsGrant('GRANT-9', $employee, 'd.fourth', $branch, false);
    rowsGrant('GRANT-8', $employee, 'c.third', null, true);
    rowsAssign('ASSIGN-7', $employee, $role, $branch);

    expect(rowsHash($employee, 3))->toBe($before);
});

it('changes when one allowed flag is flipped', function () {
    $employee = rowsEmployee('hash-one');

    rowsGrant('GRANT-1', $employee, 'c.third', null, true);

    $before = rowsHash($employee, 3);

    rowsTenant()->table('employee_permissions')
        ->where('id', rowsId('GRANT-1'))
        ->update(['allowed' => false]);

    expect(rowsHash($employee, 3))->not->toBe($before);
});

it('defines a hash for an employee with no contributions at all', function () {
    $employee = rowsEmployee('hash-empty');

    expect(rowsHash($employee, 1))->toBe(PermHash::compute(1, []))
        ->and(preg_match('/^[0-9a-f]{64}$/', rowsHash($employee, 1)))->toBe(1);
});

it('reads one employee contributions and not another', function () {
    $employee = rowsEmployee('hash-one');
    $other = rowsEmployee('hash-two');

    rowsGrant('GRANT-1', $other, 'c.third', null, true);

    // Without this, a query missing its where clause would still pass every
    // case above, because each of them builds one employee at a time.
    expect(rowsHash($employee, 1))->toBe(PermHash::compute(1, []))
        ->and(rowsHash($other, 1))->not->toBe(PermHash::compute(1, []));
});
