<?php

declare(strict_types=1);

namespace Flexipos\Access\Tests\Support;

use Flexipos\Access\Support\PermHash;
use Flexipos\Contracts\Access\EmployeeClaims;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Rows in a fixture tenant's access tables, written directly.
 *
 * **Direct inserts, deliberately, and not the task's own handlers.** A
 * resolver test seeded through `AssignRole` would be answering two questions
 * at once, and the day the assignment slice refuses something the resolver
 * fixture needs - a branch nobody is attached to, a role at a scope the
 * escalation guard dislikes - the resolver's cases would start failing for a
 * reason that has nothing to do with the resolver. The database is the
 * resolver's input; this class writes that input.
 *
 * **It writes nothing the schema would not accept.** Foreign keys are
 * enforced on the fixture connection ({@see AccessTenantFixture::enter()}),
 * so a seed naming a permission that was never declared, or an employee that
 * does not exist, fails here rather than producing a row production could
 * not hold. That is the property that makes these fixtures evidence.
 *
 * The generated `branch_id_key` sentinel columns are never written: they are
 * stored generated columns and the database computes them.
 */
final class AccessSeed
{
    /**
     * The tenant connection, by its configured name.
     */
    public static function connection(): Connection
    {
        /** @var Connection $connection */
        $connection = DB::connection(AccessTenantFixture::connectionName());

        return $connection;
    }

    /**
     * A real branch, active unless asked otherwise, and its upper-case id.
     *
     * **Since T-014 a branch is a row** (the contract migration keys every
     * branch column onto `branches`, and the application refuses an id that
     * names nothing, AC-52), so this inserts one with a unique code from a
     * counter. That one change keeps every call site meaningful: they all
     * wanted "a branch" and now get one.
     */
    public static function branch(bool $active = true): string
    {
        static $counter = 0;

        $counter++;

        $id = (string) Str::ulid();
        $now = now()->format('Y-m-d H:i:s.v');

        self::connection()->table('branches')->insert([
            'id' => $id,
            'code' => 'B'.$counter,
            'name' => 'Branch '.$counter,
            'timezone' => 'Asia/Colombo',
            'currency' => 'LKR',
            'is_active' => $active,
            'sort_order' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $id;
    }

    /**
     * A row in `permissions`, as the registry sync would have written it.
     *
     * @return string the key
     */
    public static function permission(string $key, bool $branchScoped = false): string
    {
        self::connection()->table('permissions')->insert([
            'key' => $key,
            'module' => 'fixture',
            'group' => 'fixture',
            'description' => 'A fixture permission.',
            'is_branch_scoped' => $branchScoped,
        ]);

        return $key;
    }

    /**
     * An employee.
     *
     * @param  array<string, mixed>  $overrides
     * @return string the id
     */
    public static function employee(string $code, array $overrides = []): string
    {
        $id = (string) Str::ulid();
        $now = now()->format('Y-m-d H:i:s.v');

        self::connection()->table('employees')->insert(array_merge([
            'id' => $id,
            'code' => $code,
            'user_id' => null,
            'first_name' => $code,
            'last_name' => null,
            'display_name' => $code,
            'status' => 'active',
            'perm_version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ], $overrides));

        return $id;
    }

    /**
     * A role holding these permission keys.
     *
     * @param  list<string>  $permissionKeys
     * @param  array<string, mixed>  $overrides
     * @return string the id
     */
    public static function role(string $key, array $permissionKeys = [], array $overrides = []): string
    {
        $id = (string) Str::ulid();
        $now = now()->format('Y-m-d H:i:s.v');

        self::connection()->table('roles')->insert(array_merge([
            'id' => $id,
            'key' => $key,
            'name' => $key,
            'description' => null,
            'is_system' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ], $overrides));

        foreach ($permissionKeys as $permissionKey) {
            self::connection()->table('role_permissions')->insert([
                'role_id' => $id,
                'permission_key' => $permissionKey,
            ]);
        }

        return $id;
    }

    /**
     * An employee who already holds these keys, through a role of their own.
     *
     * **What step 14's escalation rule made necessary.** AC-36 refuses a
     * grant by an actor who does not hold the key at the scope concerned,
     * so from step 14 onward a fixture that calls a granting handler needs
     * an actor with rights, not merely an id. Seeding that actor through
     * the handlers would be circular - the first assignment would need an
     * actor of its own - so it is written directly, like every other row
     * this class writes.
     *
     * `$system` makes the role a platform role, which is what AC-37's
     * owner-counting reads (`roles.is_system`, never the key).
     *
     * @param  list<string>  $permissionKeys
     * @return string the employee id
     */
    public static function actorHolding(
        string $code,
        array $permissionKeys,
        ?string $branchId = null,
        bool $system = false
    ): string {
        $employeeId = self::employee($code);
        $roleId = self::role("role_for_{$code}", $permissionKeys, ['is_system' => $system]);

        if ($branchId !== null) {
            self::attach($employeeId, $branchId);
        }

        self::assign($employeeId, $roleId, $branchId);

        return $employeeId;
    }

    /**
     * An `employee_roles` row at a scope.
     */
    public static function assign(string $employeeId, string $roleId, ?string $branchId = null): void
    {
        self::connection()->table('employee_roles')->insert([
            'id' => (string) Str::ulid(),
            'employee_id' => $employeeId,
            'role_id' => $roleId,
            'branch_id' => $branchId,
            'granted_by' => null,
            'granted_at' => now()->format('Y-m-d H:i:s.v'),
        ]);
    }

    /**
     * An `employee_permissions` row: a per-person allow or deny at a scope.
     */
    public static function personal(string $employeeId, string $permissionKey, bool $allowed, ?string $branchId = null): void
    {
        self::connection()->table('employee_permissions')->insert([
            'id' => (string) Str::ulid(),
            'employee_id' => $employeeId,
            'permission_key' => $permissionKey,
            'branch_id' => $branchId,
            'allowed' => $allowed,
        ]);
    }

    /**
     * An `employee_branches` attachment.
     */
    public static function attach(string $employeeId, string $branchId, bool $isDefault = false): void
    {
        $now = now()->format('Y-m-d H:i:s.v');

        self::connection()->table('employee_branches')->insert([
            'employee_id' => $employeeId,
            'branch_id' => $branchId,
            'is_default' => $isDefault,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * One employee's stored `perm_version`.
     */
    public static function permVersion(string $employeeId): int
    {
        return (int) self::connection()->table('employees')->where('id', $employeeId)->value('perm_version');
    }

    /**
     * A token's employee claims for a seeded employee, version-accurate.
     *
     * **For tests whose subject is something other than the claims.** From
     * step 21 the middleware refuses a token whose `perm_version` is not
     * the stored one, so every HTTP fixture needs a token that is fresh at
     * the moment it is minted; reading the version here is how it stays
     * fresh after a handler has bumped it.
     *
     * **It is deliberately not a second implementation of
     * `BuildEmployeeClaims`.** `perm_version` and `perm_hash` are read
     * from the database through the shipped helper, so they cannot drift.
     * `branch_ids` and `all_branches` are the empty shape, because no
     * middleware and no guard reads them - what they *mean* is AC-57's
     * subject and is proved against the real builder, not here. A test
     * asserting anything about those two claims must build them through
     * the claim source.
     */
    public static function tokenClaims(string $employeeId): EmployeeClaims
    {
        $version = self::permVersion($employeeId);

        return new EmployeeClaims(
            employee: $employeeId,
            branchIds: [],
            allBranches: false,
            permVersion: $version,
            permHash: PermHash::forEmployee(self::connection(), $employeeId, $version),
        );
    }

    /**
     * One employee's row, as an array, or null.
     *
     * @return array<string, mixed>|null
     */
    public static function employeeRow(string $employeeId): ?array
    {
        $row = self::connection()->table('employees')->where('id', $employeeId)->first();

        return $row === null ? null : (array) $row;
    }
}
