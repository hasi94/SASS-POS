<?php

use Flexipos\Kernel\Schema\Support\MigrationTree;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| T-010 QA - the tenant schema's shape (AC-1, AC-3, AC-9)
|--------------------------------------------------------------------------
|
| Written by QA because nothing in the suite asserted AC-3 at all, and
| AC-1's own controls ("fails against a database with one extra table and
| against one with one missing") were not exercised anywhere.
|
| **Instrument, named by what it reads.** A private in-memory SQLite
| connection this file configures, with the shipped tenant tree
| (`tenantMigrationPath()`) applied to it, listed through Laravel's schema
| builder. It reads the LIVE stand-in schema for the table and column halves
| and the migration SOURCE FILES for the docblock half, and each case says
| which. It is not `MigrationSourceRulesTest` (fixtures only) and it is not
| an `information_schema` reading: the engine half of AC-1 and AC-4 stays
| NOT VERIFIED under the engine hold.
|
| **AC-3's "exactly" is exact in both directions.** Every column list below
| is compared with `toBe()` after sorting, so an extra column and a missing
| one are both red. The lists are typed from the spec's "Data this task
| stores" table, not read back from the migrations - a list read from the
| thing under test agrees with it by construction. Two columns are not in
| that table and are declared here with the reason: the null-safe key
| (`branch_id_key`, D-10) which the table names only as "the null-safe key
| column", and `employee_branches.default_employee_id`, the stored generated
| column that carries AC-8's one-default rule (plan section 4). The second is
| the one place the spec's table and AC-8 cannot both be satisfied without
| an unlisted column; it is pinned so it cannot grow quietly.
|
*/

const QA_T10_SCHEMA_CONNECTION = 'qa_t10_schema_stand_in';

/**
 * Apply the shipped tenant tree to a fresh in-memory SQLite database.
 */
function qaT10ApplyTree(): void
{
    config()->set('database.connections.'.QA_T10_SCHEMA_CONNECTION, [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);

    app('db')->purge(QA_T10_SCHEMA_CONNECTION);

    // The stand-in guard, before anything runs: this file must never be able
    // to reach an engine.
    expect(config('database.connections.'.QA_T10_SCHEMA_CONNECTION.'.driver'))->toBe('sqlite');

    expect(Artisan::call('migrate', [
        '--database' => QA_T10_SCHEMA_CONNECTION,
        '--path' => tenantMigrationPath(),
        '--force' => true,
    ]))->toBe(0);
}

/**
 * The live columns of one table, sorted.
 *
 * @return list<string>
 */
function qaT10Columns(string $table): array
{
    $names = array_map(
        static fn (array $column): string => (string) $column['name'],
        Schema::connection(QA_T10_SCHEMA_CONNECTION)->getColumns($table)
    );

    sort($names);

    return $names;
}

/**
 * Every table in the applied schema except SQLite's own.
 *
 * @return list<string>
 */
function qaT10Tables(): array
{
    $names = array_values(array_filter(
        array_map(
            static fn (array $table): string => (string) $table['name'],
            Schema::connection(QA_T10_SCHEMA_CONNECTION)->getTables()
        ),
        static fn (string $name): bool => ! str_starts_with($name, 'sqlite_')
    ));

    sort($names);

    return $names;
}

/**
 * The listing check AC-1 describes, as a function so its controls can run it
 * against a database that is wrong.
 *
 * @param  list<string>  $expected
 * @param  list<string>  $actual
 * @return array{extra: list<string>, missing: list<string>}
 */
function qaT10ListingDiff(array $expected, array $actual): array
{
    return [
        'extra' => array_values(array_diff($actual, $expected)),
        'missing' => array_values(array_diff($expected, $actual)),
    ];
}

/**
 * The columns "Data this task stores" lists, per table, typed from the spec.
 *
 * @return array<string, list<string>>
 */
function qaT10SpecColumns(): array
{
    $lists = [
        'permissions' => ['key', 'module', 'group', 'description', 'is_branch_scoped'],
        'roles' => ['id', 'key', 'name', 'description', 'is_system', 'created_at', 'updated_at', 'deleted_at'],
        'role_permissions' => ['role_id', 'permission_key'],
        'employees' => [
            'id', 'code', 'user_id', 'first_name', 'last_name', 'display_name', 'status',
            'pin_hash', 'pin_set_at', 'pin_failed_attempts', 'pin_locked_until', 'perm_version',
            'created_at', 'updated_at', 'deleted_at', 'created_by', 'updated_by',
        ],
        // `branch_id_key` is D-10's null-safe key column.
        'employee_roles' => ['id', 'employee_id', 'role_id', 'branch_id', 'branch_id_key', 'granted_by', 'granted_at'],
        'employee_permissions' => ['id', 'employee_id', 'permission_key', 'branch_id', 'branch_id_key', 'allowed'],
        // `default_employee_id` is AC-8's stored generated column (plan section 4).
        'employee_branches' => [
            'employee_id', 'branch_id', 'is_default', 'default_employee_id', 'created_at', 'updated_at',
        ],
        'access_log' => [
            'id', 'employee_id', 'terminal_id', 'type', 'permission_key', 'branch_id', 'detail', 'ip', 'created_at',
        ],
    ];

    foreach ($lists as &$columns) {
        sort($columns);
    }

    return $lists;
}

/**
 * Columns AC-3 forbids anywhere in the new tables, as "table.column", sorted.
 *
 * @param  list<string>  $tables
 * @return list<string>
 */
function qaT10ForbiddenColumns(array $tables): array
{
    $forbidden = ['salary', 'nic', 'bank', 'department_id', 'designation_id', 'custom_fields'];
    $found = [];

    foreach ($tables as $table) {
        foreach (qaT10Columns($table) as $column) {
            if (in_array($column, $forbidden, true) || ($table === 'employees' && $column === 'branch_id')) {
                $found[] = $table.'.'.$column;
            }
        }
    }

    sort($found);

    return $found;
}

it('creates the eight tables and nothing else, and the listing check can tell otherwise (AC-1)', function () {
    qaT10ApplyTree();

    $expected = array_merge(tenantTreeTables(), ['migrations']);
    sort($expected);

    // The shipped result: no difference in either direction.
    expect(qaT10ListingDiff($expected, qaT10Tables()))->toBe(['extra' => [], 'missing' => []]);

    // The set is the six baseline tables plus exactly these eight, stated
    // independently of tenantTreeTables().
    // T-014's five tables are excluded by name, stated here and not derived:
    // the claim is about T-010's set, which they do not change.
    $t014 = ['branches', 'devices', 'locations', 'terminal_credentials', 'terminals'];

    // T-017's one table (audit_logs_archive) is excluded the same way: this
    // is still a claim about T-010's eight, unaffected by a later task's table.
    $t017 = ['audit_logs_archive'];

    // T-019's three tables are excluded the same way.
    $t019 = ['plugin_execution_logs', 'plugin_migrations', 'plugin_settings'];

    // T-020's five tables are excluded the same way.
    $t020 = ['workflow_definitions', 'workflow_events', 'workflow_instances', 'workflow_steps', 'workflow_tasks'];

    // T-021's one table (idempotency_keys) is excluded the same way.
    $t021 = ['idempotency_keys'];

    // T-027's twelve catalog tables are excluded the same way.
    $t027 = [
        'brands', 'categories', 'modifier_groups', 'modifiers', 'product_barcodes', 'product_branches',
        'product_components', 'product_locations', 'product_modifier_groups', 'products', 'unit_conversions', 'units',
    ];

    $new = array_values(array_diff(qaT10Tables(), tenantBaselineTables(), ['migrations'], $t014, $t017, $t019, $t020, $t021, $t027));

    expect($new)->toBe([
        'access_log', 'employee_branches', 'employee_permissions', 'employee_roles',
        'employees', 'permissions', 'role_permissions', 'roles',
    ]);

    // Control 1: a database with ONE EXTRA table is reported.
    Schema::connection(QA_T10_SCHEMA_CONNECTION)->create('qa_t10_extra', static function ($table): void {
        $table->string('x');
    });

    expect(qaT10ListingDiff($expected, qaT10Tables()))->toBe(['extra' => ['qa_t10_extra'], 'missing' => []]);

    Schema::connection(QA_T10_SCHEMA_CONNECTION)->drop('qa_t10_extra');

    // Control 2: a database with ONE MISSING table is reported. Dropped with
    // foreign keys off, because the point is the listing, not the cascade.
    Schema::connection(QA_T10_SCHEMA_CONNECTION)->disableForeignKeyConstraints();
    Schema::connection(QA_T10_SCHEMA_CONNECTION)->drop('access_log');
    Schema::connection(QA_T10_SCHEMA_CONNECTION)->enableForeignKeyConstraints();

    expect(qaT10ListingDiff($expected, qaT10Tables()))->toBe(['extra' => [], 'missing' => ['access_log']]);
});

it('gives the master database no table and no migration file from this task (AC-1)', function () {
    // The master tree's file names are the evidence the repository itself
    // holds; the shipped master tables are asserted by
    // MigrationTreeDisciplineTest. What is asserted HERE is the other half:
    // none of the eight names appears in any master migration file, and none
    // of the master files is dated by this task (the T-010 migrations are
    // 2026_10_20_0003xx and live in the tenant tree).
    $directory = migrationTreeDirectory(MigrationTree::Master);
    $files = glob($directory.DIRECTORY_SEPARATOR.'*.php') ?: [];

    // Non-empty, or "none of them mentions it" is also what an unread
    // directory reports.
    expect($files)->not->toBe([]);

    $names = ['permissions', 'roles', 'role_permissions', 'employees', 'employee_roles',
        'employee_permissions', 'employee_branches', 'access_log'];

    $offenders = [];

    foreach ($files as $file) {
        $source = (string) file_get_contents($file);

        foreach ($names as $name) {
            if (preg_match("/Schema::(create|table)\(\s*'".preg_quote($name, '/')."'/", $source) === 1) {
                $offenders[] = basename($file).' -> '.$name;
            }
        }

        if (str_contains(basename($file), '2026_10_20_0003')) {
            $offenders[] = basename($file).' is dated like a T-010 tenant migration';
        }
    }

    expect($offenders)->toBe([]);

    // Liveness: the same regex DOES find a create call for a table the
    // master really declares.
    $found = 0;

    foreach ($files as $file) {
        if (preg_match("/Schema::create\(\s*'users'/", (string) file_get_contents($file)) === 1) {
            $found++;
        }
    }

    expect($found)->toBe(1);
});

it('holds exactly the columns the spec lists for each table and no HR column (AC-3)', function () {
    qaT10ApplyTree();

    $spec = qaT10SpecColumns();

    // The domain, published: eight tables, and their column counts.
    $domain = [];

    foreach ($spec as $table => $columns) {
        $domain[] = $table.'('.count($columns).')';

        expect(qaT10Columns($table))->toBe($columns, "[{$table}] must hold exactly the columns the spec lists");
    }

    fwrite(STDOUT, PHP_EOL.'AC-3 columns compared: '.implode(', ', $domain).PHP_EOL);

    expect(array_keys($spec))->toHaveCount(8)
        ->and(qaT10ForbiddenColumns(array_keys($spec)))->toBe([]);
});

it('reports a table with one extra column, which is what makes the AC-3 comparison a control (AC-3)', function () {
    qaT10ApplyTree();

    $spec = qaT10SpecColumns();

    // The design's wider employees row arriving by accident: a salary column.
    Schema::connection(QA_T10_SCHEMA_CONNECTION)->table('employees', static function ($table): void {
        $table->decimal('salary', 12, 2)->nullable();
    });

    expect(qaT10Columns('employees'))->not->toBe($spec['employees'])
        ->and(qaT10ForbiddenColumns(array_keys($spec)))->toBe(['employees.salary']);
});

it('reports a table with one missing column too (AC-3)', function () {
    qaT10ApplyTree();

    $spec = qaT10SpecColumns();

    Schema::connection(QA_T10_SCHEMA_CONNECTION)->table('roles', static function ($table): void {
        $table->dropColumn('description');
    });

    expect(qaT10Columns('roles'))->not->toBe($spec['roles']);
});

it('types the three branch columns as identifiers, keys them to branches, and states who owed the keys (AC-9)', function () {
    qaT10ApplyTree();

    $nullable = [
        'employee_roles.branch_id' => true,
        'employee_permissions.branch_id' => true,
        'employee_branches.branch_id' => false,
    ];

    $directory = migrationTreeDirectory(MigrationTree::Tenant);

    $declaredThrough = [
        'employee_roles' => ['2026_10_20_000305_create_employee_roles_table.php', "IdentifierColumn::nullable(\$table, 'branch_id')"],
        'employee_permissions' => ['2026_10_20_000306_create_employee_permissions_table.php', "IdentifierColumn::nullable(\$table, 'branch_id')"],
        'employee_branches' => ['2026_10_20_000307_create_employee_branches_table.php', "IdentifierColumn::required(\$table, 'branch_id')"],
    ];

    foreach ($nullable as $site => $expected) {
        [$table, $column] = explode('.', $site);

        $live = collect(Schema::connection(QA_T10_SCHEMA_CONNECTION)->getColumns($table))->keyBy('name')->get($column);

        // The stand-in maps CHAR to varchar and drops the length, so the
        // CHAR(26) / ascii_bin half is the ENGINE half and stays NOT VERIFIED
        // (AC-4). What can be proven here: the live column is a character
        // type with the nullability the spec states, and the migration
        // declares it through the one identifier helper, which is where
        // CHAR(26) is decided.
        [$file, $call] = $declaredThrough[$table];

        expect(strtolower((string) $live['type']))->toMatch('/char/')
            ->and($live['nullable'])->toBe($expected, "[{$site}] nullability")
            ->and((string) file_get_contents($directory.DIRECTORY_SEPARATOR.$file))->toContain($call);
    }

    // T-014 step 6 flips what T-010 asserted here: the three branch columns
    // now reference `branches.id` (the contract migration, AC-3), and no other
    // key of T-010's tables does. The "FP-209 owes its foreign key" docblock
    // check in the next case still passes, because those files are untouched
    // (AC-8).
    $t010 = ['access_log', 'employee_branches', 'employee_permissions', 'employee_roles', 'employees', 'permissions', 'role_permissions', 'roles'];
    $references = [];

    foreach ($t010 as $table) {
        foreach (Schema::connection(QA_T10_SCHEMA_CONNECTION)->getForeignKeys($table) as $key) {
            $references[] = $table.'.'.implode(',', $key['columns']).' -> '.$key['foreign_table'];
        }
    }

    $ontoBranches = array_values(array_filter($references, static fn (string $r): bool => str_ends_with($r, ' -> branches')));

    sort($ontoBranches);

    expect($references)->not->toBe([])
        ->and($ontoBranches)->toBe([
            'employee_branches.branch_id -> branches',
            'employee_permissions.branch_id -> branches',
            'employee_roles.branch_id -> branches',
        ]);
});

/**
 * Does this migration source, with its comment markers removed, say that
 * FP-209 owes the foreign key and that nothing stops a bad branch id?
 */
function qaT10DocblockNamesTheOwner(string $source): bool
{
    $flat = preg_replace('/\s*\n\s*\*?\s*/', ' ', $source) ?? '';

    return str_contains($flat, 'FP-209')
        && str_contains($flat, 'owes its foreign key')
        && preg_match('/nothing in the\s+database\s+stops a bad\s+branch id/', $flat) === 1;
}

it('says in each unguarded column\'s migration that FP-209 owes the key (AC-9, source)', function () {
    $files = [
        'employee_roles' => '2026_10_20_000305_create_employee_roles_table.php',
        'employee_permissions' => '2026_10_20_000306_create_employee_permissions_table.php',
        'employee_branches' => '2026_10_20_000307_create_employee_branches_table.php',
    ];

    $directory = migrationTreeDirectory(MigrationTree::Tenant);

    foreach ($files as $table => $file) {
        $source = (string) file_get_contents($directory.DIRECTORY_SEPARATOR.$file);

        expect($source)->not->toBe('', "[{$file}] could not be read")
            ->and(qaT10DocblockNamesTheOwner($source))->toBeTrue("[{$table}] migration does not name FP-209 and the missing key");
    }

    // Control: a docblock with the owner removed is reported by the same
    // check, so the three passes above are a measurement.
    $without = "/**\n * branch_id is unguarded.\n * nothing in the database stops a bad branch id here.\n */";

    expect(qaT10DocblockNamesTheOwner($without))->toBeFalse();
});
