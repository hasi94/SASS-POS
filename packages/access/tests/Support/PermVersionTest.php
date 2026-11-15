<?php

declare(strict_types=1);

namespace Flexipos\Access\Tests\Support;

use Flexipos\Access\Support\PermVersion;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-010 step 8 - the single atomic increment
|--------------------------------------------------------------------------
|
| AC-52's shape. The behaviour - who is bumped when a role gains a key - is
| step 7's, proved in OwnerRoleSyncTest with its pair. What this file adds is
| the thing that behaviour cannot see: that the increment is ONE statement
| which reads and writes in the same breath, never a read followed by a
| write.
|
| **That distinction is invisible to every sequential test**, which is why it
| is pinned on the executed SQL rather than on an outcome: a handler that
| selected perm_version, added one in PHP and wrote it back produces exactly
| the same numbers here, and loses an increment only when two writers
| overlap. Two concurrent writers need the engine and ship NOT VERIFIED
| (AC-52 [ENGINE]); the statement pin is what is answerable today, and it is
| strictly more than the plan's "reviewable in the diff".
|
| The pin has a liveness control: the same probe, over a deliberate
| read-then-write, records the select it must be capable of seeing.
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

function versionTenant(): Connection
{
    /** @var Connection $connection */
    $connection = DB::connection(AccessTenantFixture::connectionName());

    return $connection;
}

function versionEmployee(string $stem, int $permVersion = 1): string
{
    $id = str_pad(strtoupper($stem), 26, '0');

    versionTenant()->table('employees')->insert([
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

function versionOf(string $employeeId): int
{
    return (int) versionTenant()->table('employees')->where('id', $employeeId)->value('perm_version');
}

/**
 * Every statement executed while the callback ran.
 *
 * @return list<string>
 */
function versionStatements(callable $callback): array
{
    $statements = [];

    versionTenant()->listen(function (QueryExecuted $query) use (&$statements): void {
        $statements[] = $query->sql;
    });

    $callback();

    return $statements;
}

/*
|--------------------------------------------------------------------------
| AC-52 - who moves, and by how much
|--------------------------------------------------------------------------
*/

it('raises exactly the named employees by exactly one', function () {
    $first = versionEmployee('bump-one', 3);
    $second = versionEmployee('bump-two', 7);
    $untouched = versionEmployee('bump-none', 5);

    $bumped = PermVersion::bump(versionTenant(), [$first, $second]);

    expect($bumped)->toBe(2)
        ->and(versionOf($first))->toBe(4)
        ->and(versionOf($second))->toBe(8)
        // By one, from wherever it was: a bump that set a literal would
        // pass the first two and fail here.
        ->and(versionOf($untouched))->toBe(5);
});

it('does nothing, and issues no statement, for an empty list', function () {
    $employee = versionEmployee('bump-one', 3);

    $statements = versionStatements(function () {
        expect(PermVersion::bump(versionTenant(), []))->toBe(0);
    });

    expect($statements)->toBe([])
        ->and(versionOf($employee))->toBe(3);
});

/*
|--------------------------------------------------------------------------
| AC-52 - one statement, never read-then-write
|--------------------------------------------------------------------------
*/

it('bumps with a single statement that reads and writes at once', function () {
    $employee = versionEmployee('bump-one', 3);

    $statements = versionStatements(function () use ($employee) {
        PermVersion::bump(versionTenant(), [$employee]);
    });

    $selects = array_values(array_filter(
        $statements,
        static fn (string $sql): bool => str_starts_with(strtolower(ltrim($sql)), 'select')
    ));

    expect($statements)->toHaveCount(1)
        // The increment is expressed in the statement, not computed in PHP.
        ->and($statements[0])->toContain('update')
        ->and($statements[0])->toContain('perm_version')
        ->and($statements[0])->toContain('perm_version + 1')
        // Nothing read the column first. This is the half that a sequential
        // outcome assertion cannot distinguish.
        ->and($selects)->toBe([])
        ->and(versionOf($employee))->toBe(4);
});

it('selects the holders of a role inside the update statement', function () {
    $employee = versionEmployee('bump-one', 3);
    $role = str_pad('ROLE-A', 26, '0');

    versionTenant()->table('roles')->insert([
        'id' => $role,
        'key' => 'role-a',
        'name' => 'Role A',
        'is_system' => false,
        'created_at' => '2026-10-20 00:00:00.000',
        'updated_at' => '2026-10-20 00:00:00.000',
    ]);

    versionTenant()->table('employee_roles')->insert([
        'id' => str_pad('ASSIGN-1', 26, '0'),
        'employee_id' => $employee,
        'role_id' => $role,
        'branch_id' => null,
        'granted_at' => '2026-10-20 00:00:00.000',
    ]);

    $statements = versionStatements(function () use ($role) {
        PermVersion::bumpHoldersOfRole(versionTenant(), $role);
    });

    expect($statements)->toHaveCount(1)
        // The holder list is a subquery, not a list the handler read first:
        // a read followed by a write leaves a window in which a holder
        // assigned in between is missed.
        ->and($statements[0])->toContain('employee_roles')
        ->and($statements[0])->toContain('perm_version + 1')
        ->and(versionOf($employee))->toBe(4);
});

it('can see a select when there is one to see', function () {
    // The probe's liveness control. "No select ran" and "the probe records
    // nothing" are the same observation, so the probe is shown recording
    // the exact shape it is being used to rule out.
    $employee = versionEmployee('bump-one', 3);

    $statements = versionStatements(function () use ($employee) {
        $current = versionOf($employee);

        versionTenant()->table('employees')
            ->where('id', $employee)
            ->update(['perm_version' => $current + 1]);
    });

    $selects = array_values(array_filter(
        $statements,
        static fn (string $sql): bool => str_starts_with(strtolower(ltrim($sql)), 'select')
    ));

    expect($statements)->toHaveCount(2)
        ->and($selects)->toHaveCount(1)
        ->and(versionOf($employee))->toBe(4);
});
