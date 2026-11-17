<?php

namespace Flexipos\Hr\Tests\Schema;

use Flexipos\Kernel\Schema\Support\MigrationTree;
use Flexipos\Kernel\Schema\Support\SourceRules;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| The four HR tables, applied to a SQLite stand-in (T-038 plan step 2, the
| finance shape)
|--------------------------------------------------------------------------
|
| The stand-in halves of AC-1 to AC-7, AC-28, AC-102 and AC-103. No lengths
| are asserted here: SQLite reports bare types. `HrWidthsTest` handles widths
| statically, and the `information_schema` halves are `[ENGINE]` (step 26).
*/

function hrSchemaConnection(): string
{
    return 'hr_schema_stand_in';
}

function hrApplyTenantTree(): void
{
    config()->set('database.connections.'.hrSchemaConnection(), [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);

    DB::purge(hrSchemaConnection());

    expect(Artisan::call('migrate', [
        '--database' => hrSchemaConnection(),
        '--path' => (string) config('schema.tenant_migration_path'),
        '--force' => true,
    ]))->toBe(0);
}

/**
 * @return list<string>
 */
function hrColumns(string $table): array
{
    $names = array_column(Schema::connection(hrSchemaConnection())->getColumns($table), 'name');
    sort($names);

    return $names;
}

/**
 * @return list<string>
 */
function hrNullableColumns(string $table): array
{
    $names = array_column(
        array_filter(Schema::connection(hrSchemaConnection())->getColumns($table), static fn (array $column): bool => $column['nullable']),
        'name'
    );
    sort($names);

    return $names;
}

function hrNow(): string
{
    return '2026-11-16 00:00:00.000';
}

function hrId(string $seed): string
{
    return str_pad($seed, 26, 'x');
}

function hrInsertEmployee(string $seed, ?string $deletedAt = null): string
{
    DB::connection(hrSchemaConnection())->table('employees')->insert([
        'id' => hrId($seed), 'code' => strtoupper($seed), 'first_name' => $seed, 'display_name' => $seed,
        'status' => 'active', 'created_at' => hrNow(), 'updated_at' => hrNow(), 'deleted_at' => $deletedAt,
    ]);

    return hrId($seed);
}

function hrInsertBranch(string $seed): string
{
    DB::connection(hrSchemaConnection())->table('branches')->insert([
        'id' => hrId($seed), 'code' => strtoupper($seed), 'name' => $seed, 'timezone' => 'Asia/Colombo',
        'currency' => 'LKR', 'created_at' => hrNow(), 'updated_at' => hrNow(),
    ]);

    return hrId($seed);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function hrInsertDepartment(string $seed, string $code, array $overrides = []): string
{
    DB::connection(hrSchemaConnection())->table('departments')->insert(array_merge([
        'id' => hrId($seed), 'code' => $code, 'name' => 'Department '.$code,
        'created_at' => hrNow(), 'updated_at' => hrNow(),
    ], $overrides));

    return hrId($seed);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function hrInsertDesignation(string $seed, string $code, array $overrides = []): string
{
    DB::connection(hrSchemaConnection())->table('designations')->insert(array_merge([
        'id' => hrId($seed), 'code' => $code, 'name' => 'Designation '.$code, 'level' => 1,
        'created_at' => hrNow(), 'updated_at' => hrNow(),
    ], $overrides));

    return hrId($seed);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function hrInsertProfile(string $employeeId, string $branchId, array $overrides = []): void
{
    DB::connection(hrSchemaConnection())->table('employee_profiles')->insert(array_merge([
        'employee_id' => $employeeId, 'branch_id' => $branchId, 'employment_type' => 'permanent',
        'joining_date' => '2026-01-01', 'created_at' => hrNow(), 'updated_at' => hrNow(),
    ], $overrides));
}

/**
 * @param  array<string, mixed>  $overrides
 */
function hrInsertDocument(string $seed, string $employeeId, array $overrides = []): void
{
    DB::connection(hrSchemaConnection())->table('employee_documents')->insert(array_merge([
        'id' => hrId($seed), 'employee_id' => $employeeId, 'type' => 'contract', 'title' => 'T',
        'file_path' => 'tenant/hr/employee-documents/'.$seed, 'original_name' => 'a.pdf', 'mime' => 'application/pdf',
        'size_bytes' => 10, 'checksum' => str_repeat('a', 64), 'created_at' => hrNow(), 'updated_at' => hrNow(),
    ], $overrides));
}

it('creates the four HR tables with exactly the documented columns (AC-3)', function () {
    hrApplyTenantTree();

    expect(hrColumns('departments'))->toBe([
        'branch_id', 'code', 'created_at', 'deleted_at', 'id', 'is_active', 'manager_employee_id', 'name',
        'parent_id', 'updated_at',
    ])->and(hrNullableColumns('departments'))->toBe([
        'branch_id', 'deleted_at', 'manager_employee_id', 'parent_id',
    ]);

    expect(hrColumns('designations'))->toBe([
        'code', 'created_at', 'deleted_at', 'department_id', 'id', 'is_active', 'level', 'name', 'updated_at',
    ])->and(hrNullableColumns('designations'))->toBe(['deleted_at', 'department_id']);

    expect(hrColumns('employee_profiles'))->toBe([
        'address', 'branch_id', 'confirmation_date', 'created_at', 'created_by', 'department_id', 'designation_id',
        'dob', 'emergency_contact', 'employee_id', 'employment_type', 'exit_reason', 'gender', 'joining_date',
        'last_working_date', 'marital_status', 'nationality', 'nic', 'notes', 'passport_no', 'personal_email',
        'phone', 'probation_end_date', 'reports_to_employee_id', 'resignation_date', 'updated_at', 'updated_by',
        'work_email',
    ])->and(hrNullableColumns('employee_profiles'))->toBe([
        'address', 'confirmation_date', 'created_by', 'department_id', 'designation_id', 'dob',
        'emergency_contact', 'exit_reason', 'gender', 'last_working_date', 'marital_status', 'nationality',
        'nic', 'notes', 'passport_no', 'personal_email', 'phone', 'probation_end_date',
        'reports_to_employee_id', 'resignation_date', 'updated_by', 'work_email',
    ]);

    expect(hrColumns('employee_documents'))->toBe([
        'checksum', 'created_at', 'created_by', 'deleted_at', 'employee_id', 'expiry_date', 'file_path', 'id',
        'is_confidential', 'issue_date', 'mime', 'notes', 'original_name', 'size_bytes', 'title', 'type',
        'updated_at', 'updated_by',
    ])->and(hrNullableColumns('employee_documents'))->toBe([
        'created_by', 'deleted_at', 'expiry_date', 'issue_date', 'notes', 'updated_by',
    ]);
});

it('builds none of the payroll columns and gives employees no HR column (AC-3, D-5)', function () {
    hrApplyTenantTree();

    $absent = [
        'basic_salary', 'salary_structure_id', 'pay_frequency', 'commission_plan_id', 'epf_no', 'etf_no',
        'tax_id_no', 'photo_path', 'cost_centre_account_id', 'is_sales_person', 'default_location_id',
    ];
    $all = [];

    foreach (['departments', 'designations', 'employee_profiles', 'employee_documents'] as $table) {
        $all = array_merge($all, hrColumns($table));
    }

    expect($all)->not->toBe([])->and(array_values(array_intersect($absent, $all)))->toBe([]);

    // Control: the check bites on a name that is present.
    expect(array_values(array_intersect(['dob'], $all)))->toBe(['dob']);

    // `employees` is unchanged: its identity and access columns, nothing else.
    expect(hrColumns('employees'))->toBe([
        'code', 'created_at', 'created_by', 'deleted_at', 'display_name', 'first_name', 'id', 'last_name',
        'perm_version', 'pin_failed_attempts', 'pin_hash', 'pin_locked_until', 'pin_set_at', 'status',
        'updated_at', 'updated_by', 'user_id',
    ]);
});

it('holds exactly the fourteen documented foreign keys (AC-6)', function () {
    hrApplyTenantTree();

    $keys = [];

    foreach (['departments', 'designations', 'employee_profiles', 'employee_documents'] as $table) {
        foreach (DB::connection(hrSchemaConnection())->select('pragma foreign_key_list("'.$table.'")') as $key) {
            $key = (array) $key;
            $keys[] = $table.'.'.$key['from'].' -> '.$key['table'].'.'.$key['to'].' ['.$key['on_delete'].']';
        }
    }

    sort($keys);

    expect($keys)->toHaveCount(14)->toBe([
        'departments.branch_id -> branches.id [NO ACTION]',
        'departments.manager_employee_id -> employees.id [NO ACTION]',
        'departments.parent_id -> departments.id [NO ACTION]',
        'designations.department_id -> departments.id [NO ACTION]',
        'employee_documents.created_by -> employees.id [NO ACTION]',
        'employee_documents.employee_id -> employees.id [NO ACTION]',
        'employee_documents.updated_by -> employees.id [NO ACTION]',
        'employee_profiles.branch_id -> branches.id [NO ACTION]',
        'employee_profiles.created_by -> employees.id [NO ACTION]',
        'employee_profiles.department_id -> departments.id [NO ACTION]',
        'employee_profiles.designation_id -> designations.id [NO ACTION]',
        'employee_profiles.employee_id -> employees.id [NO ACTION]',
        'employee_profiles.reports_to_employee_id -> employees.id [NO ACTION]',
        'employee_profiles.updated_by -> employees.id [NO ACTION]',
    ]);
});

it('bites on every one of the fourteen keys: a missing target is refused, a real one accepted (AC-6)', function () {
    hrApplyTenantTree();

    $employee = hrInsertEmployee('emp1');
    $branch = hrInsertBranch('br1');
    $department = hrInsertDepartment('dep1', 'D1');
    $designation = hrInsertDesignation('des1', 'G1');
    $ghost = hrId('ghost');

    // [description => [refused closure, accepted closure]]. Every refused call names a target that does not
    // exist; the accepted twin names a real one, so the cause is the key and nothing else.
    $cases = [
        'departments.parent_id' => [
            fn () => hrInsertDepartment('d2', 'D2', ['parent_id' => $ghost]),
            fn () => hrInsertDepartment('d3', 'D3', ['parent_id' => $department]),
        ],
        'departments.manager_employee_id' => [
            fn () => hrInsertDepartment('d4', 'D4', ['manager_employee_id' => $ghost]),
            fn () => hrInsertDepartment('d5', 'D5', ['manager_employee_id' => $employee]),
        ],
        'departments.branch_id' => [
            fn () => hrInsertDepartment('d6', 'D6', ['branch_id' => $ghost]),
            fn () => hrInsertDepartment('d7', 'D7', ['branch_id' => $branch]),
        ],
        'designations.department_id' => [
            fn () => hrInsertDesignation('g2', 'G2', ['department_id' => $ghost]),
            fn () => hrInsertDesignation('g3', 'G3', ['department_id' => $department]),
        ],
        'employee_profiles.employee_id' => [
            fn () => hrInsertProfile($ghost, $branch),
            fn () => hrInsertProfile($employee, $branch),
        ],
        'employee_profiles.branch_id' => [
            fn () => hrInsertProfile(hrInsertEmployee('emp2'), $ghost),
            fn () => hrInsertProfile(hrInsertEmployee('emp3'), $branch),
        ],
        'employee_profiles.reports_to_employee_id' => [
            fn () => hrInsertProfile(hrInsertEmployee('emp4'), $branch, ['reports_to_employee_id' => $ghost]),
            fn () => hrInsertProfile(hrInsertEmployee('emp5'), $branch, ['reports_to_employee_id' => $employee]),
        ],
        'employee_profiles.created_by' => [
            fn () => hrInsertProfile(hrInsertEmployee('emp6'), $branch, ['created_by' => $ghost]),
            fn () => hrInsertProfile(hrInsertEmployee('emp7'), $branch, ['created_by' => $employee]),
        ],
        'employee_profiles.updated_by' => [
            fn () => hrInsertProfile(hrInsertEmployee('emp8'), $branch, ['updated_by' => $ghost]),
            fn () => hrInsertProfile(hrInsertEmployee('emp9'), $branch, ['updated_by' => $employee]),
        ],
        'employee_profiles.department_id' => [
            fn () => hrInsertProfile(hrInsertEmployee('empa'), $branch, ['department_id' => $ghost]),
            fn () => hrInsertProfile(hrInsertEmployee('empb'), $branch, ['department_id' => $department]),
        ],
        'employee_profiles.designation_id' => [
            fn () => hrInsertProfile(hrInsertEmployee('empc'), $branch, ['designation_id' => $ghost]),
            fn () => hrInsertProfile(hrInsertEmployee('empd'), $branch, ['designation_id' => $designation]),
        ],
        'employee_documents.employee_id' => [
            fn () => hrInsertDocument('x1', $ghost),
            fn () => hrInsertDocument('x2', $employee),
        ],
        'employee_documents.created_by' => [
            fn () => hrInsertDocument('x3', $employee, ['created_by' => $ghost]),
            fn () => hrInsertDocument('x4', $employee, ['created_by' => $employee]),
        ],
        'employee_documents.updated_by' => [
            fn () => hrInsertDocument('x5', $employee, ['updated_by' => $ghost]),
            fn () => hrInsertDocument('x6', $employee, ['updated_by' => $employee]),
        ],
    ];

    expect($cases)->toHaveCount(14);

    foreach ($cases as $name => [$refused, $accepted]) {
        try {
            $refused();
            $outcome = 'accepted';
        } catch (QueryException) {
            $outcome = 'refused';
        }

        expect([$name => $outcome])->toBe([$name => 'refused']);

        $accepted();
    }
});

it('refuses deleting a referenced employee, department, designation or branch row directly (AC-6)', function () {
    hrApplyTenantTree();

    $connection = DB::connection(hrSchemaConnection());
    $employee = hrInsertEmployee('emp1');
    $branch = hrInsertBranch('br1');
    $department = hrInsertDepartment('dep1', 'D1');
    $designation = hrInsertDesignation('des1', 'G1', ['department_id' => $department]);
    hrInsertProfile($employee, $branch, ['department_id' => $department, 'designation_id' => $designation]);
    hrInsertDocument('x1', $employee);

    foreach ([['employees', $employee], ['branches', $branch], ['departments', $department], ['designations', $designation]] as [$table, $id]) {
        expect(fn () => $connection->table($table)->where('id', $id)->delete())->toThrow(QueryException::class);
    }

    // Control: an unreferenced designation row deletes.
    $free = hrInsertDesignation('des2', 'G2');
    $connection->table('designations')->where('id', $free)->delete();

    expect($connection->table('designations')->count())->toBe(1);
});

it('holds departments.code and designations.code unique across live and soft-deleted rows (AC-7)', function () {
    hrApplyTenantTree();

    hrInsertDepartment('a1', 'SALES');
    hrInsertDesignation('b1', 'MGR');

    expect(fn () => hrInsertDepartment('a2', 'SALES'))->toThrow(QueryException::class)
        ->and(fn () => hrInsertDesignation('b2', 'MGR'))->toThrow(QueryException::class);

    // A soft-deleted holder keeps its code reserved.
    hrInsertDepartment('a3', 'OPS', ['deleted_at' => hrNow()]);
    hrInsertDesignation('b3', 'CLK', ['deleted_at' => hrNow()]);

    expect(fn () => hrInsertDepartment('a4', 'OPS'))->toThrow(QueryException::class)
        ->and(fn () => hrInsertDesignation('b4', 'CLK'))->toThrow(QueryException::class);

    // Control: a different code is accepted.
    hrInsertDepartment('a5', 'OPS2');
    hrInsertDesignation('b5', 'CLK2');

    expect(DB::connection(hrSchemaConnection())->table('departments')->count())->toBe(3);
});

it('indexes the documented lookup columns (AC-3)', function () {
    hrApplyTenantTree();

    $indexes = static function (string $table): array {
        $columns = array_map(
            static fn (array $index): string => implode(',', $index['columns']).($index['unique'] ? ' unique' : ''),
            Schema::connection(hrSchemaConnection())->getIndexes($table)
        );
        sort($columns);

        return $columns;
    };

    expect($indexes('departments'))->toBe(['code unique', 'id unique', 'parent_id'])
        ->and($indexes('designations'))->toBe(['code unique', 'department_id', 'id unique'])
        ->and($indexes('employee_documents'))->toBe(['employee_id,type', 'expiry_date', 'id unique'])
        ->and($indexes('employee_profiles'))->toBe([
            'branch_id', 'department_id', 'designation_id', 'employee_id unique', 'reports_to_employee_id',
        ]);
});

it('creates no row in any HR table, and leaves existing employees untouched (AC-28, AC-103)', function () {
    hrApplyTenantTree();

    foreach (['departments', 'designations', 'employee_profiles', 'employee_documents'] as $table) {
        expect(DB::connection(hrSchemaConnection())->table($table)->count())->toBe(0);
    }
});

it('leaves the rows of employees byte-identical across the HR migrations (AC-103)', function () {
    config()->set('database.connections.'.hrSchemaConnection(), [
        'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
    ]);
    DB::purge(hrSchemaConnection());

    $directory = base_path((string) config('schema.tenant_migration_path'));
    $before = array_values(array_filter(
        (array) glob($directory.'/*.php'),
        static fn (string $file): bool => ! str_contains($file, '2026_11_16_0038')
    ));

    // The prior tree, in a scratch directory, so the HR files really are the only difference.
    $scratch = sys_get_temp_dir().'/t038a_prior_'.bin2hex(random_bytes(4));
    mkdir($scratch);

    foreach ($before as $file) {
        copy($file, $scratch.'/'.basename($file));
    }

    try {
        expect(Artisan::call('migrate', [
            '--database' => hrSchemaConnection(), '--path' => $scratch, '--realpath' => true, '--force' => true,
        ]))->toBe(0);

        $employee = hrInsertEmployee('emp1');
        $hash = static fn (): string => hash('sha256', (string) json_encode(
            DB::connection(hrSchemaConnection())->table('employees')->orderBy('id')->get()->all()
        ));
        $columnsBefore = hrColumns('employees');
        $hashBefore = $hash();

        expect(Artisan::call('migrate', [
            '--database' => hrSchemaConnection(), '--path' => $directory, '--realpath' => true, '--force' => true,
        ]))->toBe(0);

        expect($hash())->toBe($hashBefore)
            ->and(hrColumns('employees'))->toBe($columnsBefore)
            ->and(Schema::connection(hrSchemaConnection())->hasTable('employee_profiles'))->toBeTrue()
            ->and(DB::connection(hrSchemaConnection())->table('employee_profiles')->count())->toBe(0)
            ->and(DB::connection(hrSchemaConnection())->table('employees')->where('id', $employee)->exists())->toBeTrue();
    } finally {
        foreach ((array) glob($scratch.'/*') as $file) {
            unlink($file);
        }

        rmdir($scratch);
    }
});

it('runs cleanly twice as a no-op, and completes a partial tenant in order (AC-102)', function () {
    hrApplyTenantTree();

    $before = Schema::connection(hrSchemaConnection())->getTableListing();

    expect(Artisan::call('migrate', [
        '--database' => hrSchemaConnection(),
        '--path' => (string) config('schema.tenant_migration_path'),
        '--force' => true,
    ]))->toBe(0);

    expect(Schema::connection(hrSchemaConnection())->getTableListing())->toBe($before);

    // A tenant that received only the first two files completes the remainder.
    DB::connection(hrSchemaConnection())->statement('drop table employee_documents');
    DB::connection(hrSchemaConnection())->statement('drop table employee_profiles');
    DB::connection(hrSchemaConnection())->table('migrations')->whereIn('migration', [
        '2026_11_16_003803_create_employee_profiles_table', '2026_11_16_003804_create_employee_documents_table',
    ])->delete();

    expect(Artisan::call('migrate', [
        '--database' => hrSchemaConnection(),
        '--path' => (string) config('schema.tenant_migration_path'),
        '--force' => true,
    ]))->toBe(0)
        ->and(Schema::connection(hrSchemaConnection())->getTableListing())->toBe($before);
});

it('declares every HR migration expand with no down() and no tenant_id column (AC-2 source half, AC-4)', function () {
    $files = glob(base_path((string) config('schema.tenant_migration_path')).'/2026_11_16_00380[1234]_*.php');

    // Four files scanned, so an empty result is evidence and not an empty glob.
    expect($files)->toHaveCount(4);

    foreach ($files as $file) {
        $source = (string) file_get_contents($file);

        expect($source)->toContain('@phase expand')
            ->and($source)->not->toContain('function down(')
            ->and(SourceRules::codeOnly($source))->not->toContain('tenant_id');
    }
});

it('reports no SourceRules violation across the four HR table migrations (AC-4)', function () {
    $violations = [];
    $files = glob(base_path((string) config('schema.tenant_migration_path')).'/2026_11_16_00380[1234]_*.php');

    expect($files)->toHaveCount(4);

    foreach ($files as $file) {
        $violations = array_merge($violations, SourceRules::inspect((string) file_get_contents($file), MigrationTree::Tenant));
    }

    expect($violations)->toBe([]);
});

it('red-first: a fixture table with one extra column is reported by the column check (AC-3 control)', function () {
    hrApplyTenantTree();

    Schema::connection(hrSchemaConnection())->table('departments', function ($table) {
        $table->string('cost_centre_account_id', 26)->nullable();
    });

    expect(hrColumns('departments'))->toContain('cost_centre_account_id')
        ->and(hrColumns('departments'))->not->toBe([
            'branch_id', 'code', 'created_at', 'deleted_at', 'id', 'is_active', 'manager_employee_id', 'name',
            'parent_id', 'updated_at',
        ]);
});
