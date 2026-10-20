<?php

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| T-010 step 4 - the three keys, proved by direct insert
|--------------------------------------------------------------------------
|
| AC-6 (the null-safe uniqueness of `employee_roles` and
| `employee_permissions`), AC-7 (`employees.code` and `employees.user_id`) and
| AC-8 (one default branch per employee). The subject is the SHIPPED schema:
| every case migrates the real tenant tree onto a private in-memory SQLite
| connection and then writes rows through it. Nothing here is a model, a
| handler or a validator - the question is what the database refuses.
|
| **Every case carries a discriminating control, and for AC-6 that is not
| optional** (Ruling 4). SQLite accepts NULL in a non-integer primary key,
| measured in step 2, so a test that merely asserted "the null-branch row
| stores" would pass against the broken design the documents print. The
| control is the same three inserts against a fixture table built with a
| PLAIN unique key over the nullable column, shown to **accept** the
| duplicate. That contrast is a difference the stand-in can see, and it is
| what makes the green mean something here.
|
| **[ENGINE] halves are not claimed.** AC-6's two-engine comparison and
| AC-8's concurrent writers need MySQL and MariaDB and ship NOT VERIFIED
| while ENGINE_HOLD stands. What is answered here is the refusal, on the
| stand-in, with its control beside it.
|
| Nothing in this file can reach the engine: it names one connection, that
| connection is asserted to be SQLite, and the assertion comes before the
| migration. It spawns no child process, so it stays in `composer test:fast`
| and must NOT be added to toolchainGroupFiles().
|
*/

/**
 * The name of this file's stand-in connection.
 *
 * Its own, not `tenant` - that stanza is T-003's sentinel - and not
 * TenantBaselineBuildsTest's either: that file declares its fixture at file
 * scope, and a filtered single-file run of this one would never load it.
 */
function accessKeysStandIn(): string
{
    return 'tenant_access_keys_stand_in';
}

/**
 * Configure the stand-in and apply the whole shipped tenant tree to it.
 */
function applyAccessKeysTree(): void
{
    config()->set('database.connections.'.accessKeysStandIn(), [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        // Without this SQLite ignores every foreign key, and the fixtures
        // below reference `employees`, `roles` and `permissions`.
        'foreign_key_constraints' => true,
    ]);

    expect(config('database.connections.'.accessKeysStandIn().'.driver'))->toBe('sqlite');

    expect(Artisan::call('migrate', [
        '--database' => accessKeysStandIn(),
        '--path' => tenantMigrationPath(),
        '--force' => true,
    ]))->toBe(0);
}

/**
 * A 26-character identifier built from a readable stem.
 */
function accessKeysId(string $stem): string
{
    return str_pad(strtoupper($stem), 26, '0');
}

/**
 * Insert one employee and return its id.
 */
function accessKeysEmployee(string $stem, ?string $userId = null, ?string $deletedAt = null, ?string $code = null): string
{
    $id = accessKeysId($stem);

    DB::connection(accessKeysStandIn())->table('employees')->insert([
        'id' => $id,
        'code' => $code ?? $stem,
        'user_id' => $userId,
        'first_name' => 'Test',
        'display_name' => 'Test',
        'status' => 'active',
        'perm_version' => 1,
        'created_at' => '2026-10-20 00:00:00.000',
        'updated_at' => '2026-10-20 00:00:00.000',
        'deleted_at' => $deletedAt,
    ]);

    return $id;
}

/*
|--------------------------------------------------------------------------
| AC-6 - the null-safe keys, and the plain key that cannot tell them apart
|--------------------------------------------------------------------------
*/

it('refuses a second all-branches role assignment and accepts a distinct branch', function () {
    applyAccessKeysTree();

    $employee = accessKeysEmployee('emp-one');
    $role = accessKeysId('role-one');

    DB::connection(accessKeysStandIn())->table('roles')->insert([
        'id' => $role,
        'key' => 'cashier',
        'name' => 'Cashier',
        'is_system' => false,
        'created_at' => '2026-10-20 00:00:00.000',
        'updated_at' => '2026-10-20 00:00:00.000',
    ]);

    $assign = static fn (string $stem, ?string $branch) => DB::connection(accessKeysStandIn())
        ->table('employee_roles')
        ->insert([
            'id' => accessKeysId($stem),
            'employee_id' => $employee,
            'role_id' => $role,
            'branch_id' => $branch,
            'granted_at' => '2026-10-20 00:00:00.000',
        ]);

    // Each row carries its own surrogate id, so the only key a second insert
    // can violate is the one under test. A fixture reusing the id would be
    // refused by the primary key and the sentinel could be deleted without
    // this test noticing.
    $assign('er-null-a', null);

    expect(static fn () => $assign('er-null-b', null))
        ->toThrow(QueryException::class, 'branch_id_key');

    $assign('er-branch-a', accessKeysId('branch-a'));

    expect(static fn () => $assign('er-branch-b', accessKeysId('branch-a')))
        ->toThrow(QueryException::class, 'branch_id_key');

    // A distinct branch is still accepted, so the key constrains the pair and
    // not the employee.
    $assign('er-branch-c', accessKeysId('branch-b'));

    expect(DB::connection(accessKeysStandIn())->table('employee_roles')->count())->toBe(3);
});

it('refuses a second all-branches per-person grant and accepts a distinct branch', function () {
    applyAccessKeysTree();

    $employee = accessKeysEmployee('emp-two');

    DB::connection(accessKeysStandIn())->table('permissions')->insert([
        'key' => 'employees.view',
        'module' => 'access',
        'group' => 'Access',
        'description' => 'See employees.',
        'is_branch_scoped' => false,
    ]);

    $grant = static fn (string $stem, ?string $branch) => DB::connection(accessKeysStandIn())
        ->table('employee_permissions')
        ->insert([
            'id' => accessKeysId($stem),
            'employee_id' => $employee,
            'permission_key' => 'employees.view',
            'branch_id' => $branch,
            'allowed' => true,
        ]);

    // The row the printed composite primary key could not have stored at all
    // (D-10): an all-branches per-person grant.
    $grant('ep-null-a', null);

    expect(DB::connection(accessKeysStandIn())->table('employee_permissions')
        ->whereNull('branch_id')->count())->toBe(1);

    expect(static fn () => $grant('ep-null-b', null))
        ->toThrow(QueryException::class, 'branch_id_key');

    $grant('ep-branch-a', accessKeysId('branch-a'));

    expect(static fn () => $grant('ep-branch-b', accessKeysId('branch-a')))
        ->toThrow(QueryException::class, 'branch_id_key');

    expect(DB::connection(accessKeysStandIn())->table('employee_permissions')->count())->toBe(2);
});

it('shows a plain unique key over the nullable column accepting the duplicate it should refuse', function () {
    applyAccessKeysTree();

    // Ruling 4's control, and AC-6 is worth nothing without it. This fixture
    // table is the shape the two real tables would have had WITHOUT the
    // sentinel: a plain unique key over the nullable branch. The identical
    // inserts run against it.
    Schema::connection(accessKeysStandIn())->create('qa_plain_key_control', function (Blueprint $table) {
        $table->ulid('id')->primary();
        $table->ulid('employee_id');
        $table->ulid('role_id');
        $table->ulid('branch_id')->nullable();

        $table->unique(['employee_id', 'role_id', 'branch_id']);
    });

    $insert = static fn (string $stem, ?string $branch) => DB::connection(accessKeysStandIn())
        ->table('qa_plain_key_control')
        ->insert([
            'id' => accessKeysId($stem),
            'employee_id' => accessKeysId('emp-one'),
            'role_id' => accessKeysId('role-one'),
            'branch_id' => $branch,
        ]);

    $insert('pk-null-a', null);

    // ACCEPTED - and this is the whole point. NULLs are distinct in a unique
    // index on both engines, so the duplicate the sentinel refuses above is
    // stored here without complaint. The two shapes are therefore
    // distinguishable on the stand-in, and the green above is not equally
    // consistent with the broken design.
    $insert('pk-null-b', null);

    expect(DB::connection(accessKeysStandIn())->table('qa_plain_key_control')
        ->whereNull('branch_id')->count())->toBe(2);

    // And the plain key is not simply inert: a duplicate with a NON-null
    // branch is still refused, so what the control demonstrates is the NULL
    // hole specifically and not a missing index.
    $insert('pk-branch-a', accessKeysId('branch-a'));

    expect(static fn () => $insert('pk-branch-b', accessKeysId('branch-a')))
        ->toThrow(QueryException::class);
});

/*
|--------------------------------------------------------------------------
| AC-7 - code and user_id
|--------------------------------------------------------------------------
*/

it('reserves an employee code across a soft deletion', function () {
    applyAccessKeysTree();

    accessKeysEmployee('emp-gone', null, '2026-10-20 12:00:00.000', 'C-100');

    // The soft-deleted row still holds the code: a code is never reused, so
    // payroll and audit history stay unambiguous (D-14).
    expect(static fn () => accessKeysEmployee('emp-new', null, null, 'C-100'))
        ->toThrow(QueryException::class, 'code');

    // The positive control in the same case: a different code is accepted, so
    // the refusal is about the value and not about inserting at all.
    accessKeysEmployee('emp-new', null, null, 'C-101');

    expect(DB::connection(accessKeysStandIn())->table('employees')->count())->toBe(2);
});

it('accepts two employees with no web login and refuses two claiming one account', function () {
    applyAccessKeysTree();

    // The positive control, in the same case as the refusal: `user_id` is
    // null for a cashier with no web login, and that is the ordinary row -
    // NULLs are distinct in a unique index, which is the legitimate use of
    // the nullable-unique shape (not section 3.3's defect).
    accessKeysEmployee('emp-a', null, null, 'C-200');
    accessKeysEmployee('emp-b', null, null, 'C-201');

    expect(DB::connection(accessKeysStandIn())->table('employees')->whereNull('user_id')->count())->toBe(2);

    accessKeysEmployee('emp-c', accessKeysId('user-one'), null, 'C-202');

    expect(static fn () => accessKeysEmployee('emp-d', accessKeysId('user-one'), null, 'C-203'))
        ->toThrow(QueryException::class, 'user_id');

    expect(DB::connection(accessKeysStandIn())->table('employees')->count())->toBe(3);
});

/*
|--------------------------------------------------------------------------
| AC-8 - one default branch per employee
|--------------------------------------------------------------------------
*/

it('allows one default branch per employee, any number of non-default ones, and none at all', function () {
    applyAccessKeysTree();

    $one = accessKeysEmployee('emp-one', null, null, 'C-300');
    $two = accessKeysEmployee('emp-two', null, null, 'C-301');
    $three = accessKeysEmployee('emp-three', null, null, 'C-302');

    $attach = static fn (string $employee, string $branch, bool $default) => DB::connection(accessKeysStandIn())
        ->table('employee_branches')
        ->insert([
            'employee_id' => $employee,
            'branch_id' => accessKeysId($branch),
            'is_default' => $default,
            'created_at' => '2026-10-20 00:00:00.000',
            'updated_at' => '2026-10-20 00:00:00.000',
        ]);

    $attach($one, 'branch-a', true);

    // Two further NON-default rows for the same employee. This is the case a
    // plain unique key on (employee_id, is_default) would wrongly refuse, and
    // it is why the generated column exists rather than that index.
    $attach($one, 'branch-b', false);
    $attach($one, 'branch-c', false);

    expect(static fn () => $attach($one, 'branch-d', true))
        ->toThrow(QueryException::class, 'default_employee_id');

    // Another employee may have a default of their own: the key is per
    // employee, not per branch and not global.
    $attach($two, 'branch-a', true);

    // And an employee attached to a branch with no default at all is allowed.
    $attach($three, 'branch-a', false);

    expect(DB::connection(accessKeysStandIn())->table('employee_branches')->count())->toBe(5)
        ->and(DB::connection(accessKeysStandIn())->table('employee_branches')->where('is_default', true)->count())->toBe(2)
        ->and(DB::connection(accessKeysStandIn())->table('employee_branches')
            ->where('default_employee_id', $one)->value('branch_id'))->toBe(accessKeysId('branch-a'));
});

it('shows a plain unique key on (employee_id, is_default) refusing the second non-default row', function () {
    applyAccessKeysTree();

    // AC-8's discriminating control, and its shape differs from AC-6's on
    // purpose: here the rejected alternative does not accept too much, it
    // refuses too much. A unique index over (employee_id, is_default) - the
    // obvious implementation - also refuses a second NON-default attachment,
    // which is the ordinary row. A partial index, which would express the
    // rule directly, exists on neither MySQL nor MariaDB.
    Schema::connection(accessKeysStandIn())->create('qa_plain_default_control', function (Blueprint $table) {
        $table->ulid('employee_id');
        $table->ulid('branch_id');
        $table->boolean('is_default')->default(false);

        $table->primary(['employee_id', 'branch_id']);
        $table->unique(['employee_id', 'is_default']);
    });

    $attach = static fn (string $branch, bool $default) => DB::connection(accessKeysStandIn())
        ->table('qa_plain_default_control')
        ->insert([
            'employee_id' => accessKeysId('emp-one'),
            'branch_id' => accessKeysId($branch),
            'is_default' => $default,
        ]);

    $attach('branch-a', true);
    $attach('branch-b', false);

    // REFUSED by the alternative, accepted by the shipped table above. That
    // pair is what makes the generated column a decision rather than a
    // flourish.
    expect(static fn () => $attach('branch-c', false))
        ->toThrow(QueryException::class, 'is_default');
});
