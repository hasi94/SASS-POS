<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| The master schema, applied to the suite's stand-in connection
|--------------------------------------------------------------------------
|
| T-008 step 3. **This is not AC-1's evidence and must not be recorded as it.**
| AC-1, AC-2, AC-5 to AC-11 and AC-36 are [E] criteria: they are answered by
| reading `information_schema` on the engine the environment names, and while
| ENGINE_HOLD is set they are NOT VERIFIED, waiting on gate G-1 (AC-63, D-35).
|
| What this file does answer is the part that needs no engine and that nothing
| else in the branch would notice:
|
| - every migration in the tree **applies**, in the order their file names impose,
|   with every foreign key finding its table;
| - a second run changes nothing and exits 0 (AC-1's idempotence half);
| - the applied schema holds exactly AC-1's tables and no others;
| - every application table is empty - seeding belongs to FP-206, FP-207 and
|   FP-210 (AC-11's shape);
| - no column in any master table is named like something that could hold a
|   credential (AC-6's shape), with a planted column as the control.
|
| The connection is the suite's in-memory SQLite master, and the first assertion
| in every case below is that it *is* SQLite. That is not decoration: an ambient
| DB_MASTER_DRIVER beats phpunit.xml's <env> (it writes putenv() and $_ENV, never
| $_SERVER, which Laravel's Env repository reads first), so without that
| assertion a developer's shell could point this file at the shared engine -
| which is on hold after sixteen of the owner's databases were dropped on
| 2026-10-14. The guard fails before `migrate` is called, so this file is
| structurally incapable of writing to that server.
|
| SQLite is a stand-in and differs from the engine in ways the design knows
| about: it has no `ascii_bin` collation, no `ALTER TABLE … ADD CONSTRAINT`, and
| no second auto-increment column. `IdentifierColumn`, `JsonValidity` and
| `tenants`' sequence statement each say so where they skip.
|
| This file spawns no child process, so it stays in `composer test:fast` and
| must NOT be added to toolchainGroupFiles().
|
*/

/**
 * Fail unless the master connection is the suite's stand-in.
 *
 * Called first in every case here. `config()` is read rather than the
 * connection: reading configuration cannot open a socket.
 */
function masterStandInIsConfigured(): void
{
    // `--database=master` is passed to every migrate call below rather than
    // relying on the default connection. In the shipped configuration the
    // default *is* `master` (config/database.php), which is what AC-22 rests
    // on - but phpunit.xml sets DB_CONNECTION=sqlite for the suite, so a plain
    // `migrate` here applies the tree to a different in-memory database and
    // every assertion afterwards reads an empty schema. Measured: it exits 0
    // while creating nothing the test can see.
    expect(config('database.connections.master.driver'))->toBe('sqlite')
        ->and(config('database.connections.master.database'))->toBe(':memory:');
}

/**
 * Every table in the applied stand-in schema.
 *
 * Filtered in PHP rather than with a SQL `LIKE`, which this project does not
 * use for enumeration at all: `_` is a single-character wildcard there, and the
 * habit is what made `SHOW DATABASES LIKE 'flexipos_%'` return the owner's real
 * databases (D-30, D-34).
 *
 * @return list<string>
 */
function masterStandInTables(): array
{
    $tables = [];

    foreach (DB::connection('master')->select("select name from sqlite_master where type = 'table'") as $row) {
        $name = (string) ((array) $row)['name'];

        if (str_starts_with($name, 'sqlite_')) {
            continue;
        }

        $tables[] = $name;
    }

    sort($tables);

    return $tables;
}

/**
 * Column names on a table that could hold a credential value.
 *
 * The names come from AC-6: `password`, `*_password`, `*_secret`, and
 * `credential` without the `_ref` suffix that marks a reference rather than a
 * value.
 *
 * **The exemption is the column's type, never its name.** Three columns the
 * design requires carry a credential-shaped name and cannot hold a credential:
 * `platform_settings.is_secret` is the boolean flag for "do not print this value
 * in the platform UI" (section 4.9), and `tenant_databases.credential_version`
 * and `credential_rotated_at` are an integer counter and a datetime - the two
 * columns that make a rotation invalidate a cached mapping (AC-14, AC-16). A
 * number cannot hold a password. So the detector asks the schema what the column
 * *is*, not whether its name is on a list of blessed exceptions: an allow-list
 * of names is how this kind of check rots, and the first entry is always for a
 * column that "obviously" does not mean it. The same names on a text column are
 * still reported, which is the control below.
 *
 * @param  list<array{name: string, type_name: string}>  $columns
 * @return list<string>
 */
function credentialShapedColumns(array $columns): array
{
    $found = [];

    foreach ($columns as $column) {
        $name = strtolower($column['name']);

        $suspicious = $name === 'password'
            || $name === 'credential'
            || str_ends_with($name, '_password')
            || str_ends_with($name, '_secret')
            || (str_contains($name, 'credential') && ! str_ends_with($name, '_ref'));

        if (! $suspicious) {
            continue;
        }

        // Types that cannot hold a secret: a flag, a counter and a moment.
        $harmlessTypes = [
            'tinyint', 'boolean', 'bool', 'bit',
            'int', 'integer', 'smallint', 'mediumint', 'bigint',
            'decimal', 'numeric', 'float', 'double',
            'date', 'datetime', 'timestamp', 'time', 'year',
        ];

        if (in_array(strtolower($column['type_name']), $harmlessTypes, true)) {
            continue;
        }

        $found[] = $column['name'];
    }

    return $found;
}

/*
|--------------------------------------------------------------------------
| It applies, twice
|--------------------------------------------------------------------------
*/

it('applies the whole master tree, and a second run changes nothing', function () {
    masterStandInIsConfigured();

    expect(Artisan::call('migrate', ['--database' => 'master', '--force' => true]))->toBe(0);

    $afterFirstRun = masterStandInTables();

    $expected = array_merge(masterApplicationTables(), masterSystemTables(), ['migrations']);
    sort($expected);

    // Exact equality: "and nothing else" is half of AC-1, and it is the half an
    // extra table slips through.
    expect($afterFirstRun)->toBe($expected);

    // Idempotence. The framework's `migrations` table is what makes the second
    // run a no-op, so this also asserts the tree is recorded rather than merely
    // applied.
    expect(Artisan::call('migrate', ['--database' => 'master', '--force' => true]))->toBe(0)
        ->and(masterStandInTables())->toBe($afterFirstRun);
});

it('leaves every application table empty', function () {
    masterStandInIsConfigured();

    Artisan::call('migrate', ['--database' => 'master', '--force' => true]);

    $counts = [];

    foreach (masterApplicationTables() as $table) {
        $counts[$table] = DB::connection('master')->table($table)->count();
    }

    expect($counts)->toBe(array_fill_keys(masterApplicationTables(), 0));

    // The control: the same count over a table that does hold a row reports it,
    // so "all zero" is not what an unreadable schema looks like.
    DB::connection('master')->table('feature_flags')->insert([
        'key' => 'control.row',
        'default_enabled' => false,
        'owner_module' => 'test',
        'is_plugin_flag' => false,
    ]);

    expect(DB::connection('master')->table('feature_flags')->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| No column that could hold a credential
|--------------------------------------------------------------------------
*/

it('has no credential-shaped column in any master table', function () {
    masterStandInIsConfigured();

    Artisan::call('migrate', ['--database' => 'master', '--force' => true]);

    $offenders = [];

    foreach (masterApplicationTables() as $table) {
        /** @var list<array{name: string, type_name: string}> $columns */
        $columns = Schema::connection('master')->getColumns($table);

        $found = credentialShapedColumns($columns);

        if ($found !== []) {
            $offenders[$table] = $found;
        }
    }

    expect($offenders)->toBe([]);

    // And the one password-shaped name the master is allowed: a hash, on the
    // table that authenticates a human. If this disappears, the assertion above
    // has stopped reading anything.
    $userColumns = array_column(Schema::connection('master')->getColumns('users'), 'name');

    expect($userColumns)->toContain('password_hash')
        ->and($userColumns)->not->toContain('password')
        ->and($userColumns)->not->toContain('remember_token');
});

it('reports a planted credential column, and not the design flag of the same shape', function () {
    masterStandInIsConfigured();

    // The positive control AC-6 names, built as real columns on the stand-in so
    // the detector is handed what the engine would hand it.
    Schema::connection('master')->create('control_credentials', function ($table) {
        $table->string('app_password', 255);
        $table->string('api_secret', 255);
        $table->string('db_credential', 255);
        $table->string('app_credential_ref', 255);
        $table->string('password_hash', 255);
        $table->boolean('is_secret');
        $table->integer('credential_version');
        // The negative control that matters most: the same name as the design's
        // own counter, on a column that *could* hold a secret, is reported.
        $table->string('credential_version_text', 255);
    });

    /** @var list<array{name: string, type_name: string}> $columns */
    $columns = Schema::connection('master')->getColumns('control_credentials');

    $found = credentialShapedColumns($columns);
    sort($found);

    // A reference column and a hash are not values; a boolean and an integer
    // cannot hold one; the same credential name on a string column can.
    expect($found)->toBe(['api_secret', 'app_password', 'credential_version_text', 'db_credential']);
});

/*
|--------------------------------------------------------------------------
| `sessions` carries no foreign key to `users`
|--------------------------------------------------------------------------
|
| T-008 step 13, and plan section 3 finding 6. `users.id` is a ULID CHAR(26)
| and the stock `sessions.user_id` is a bigint. Correcting that is FP-207 and
| is out of scope here; what is in scope is that the mismatch cannot surface
| as a failing insert in production. The stock migration ships no key, so this
| is a guard against one being added by a well-meaning later edit - on the day
| it is added, the two column types have to be reconciled first, and this test
| is where that is said.
|
| Read from the applied schema rather than from the migration source: a key can
| be added by an ALTER in a later migration, and the source of the file that
| creates the table would not know.
|
*/

/**
 * The foreign keys one table carries, as "column -> table.column".
 *
 * SQLite's `pragma foreign_key_list` answers whatever the schema declares,
 * whether or not the connection enforces keys - and the master stanza does not
 * enforce them - so the reading is about the schema and not about enforcement.
 *
 * @return list<string>
 */
function masterStandInForeignKeys(string $table): array
{
    $keys = [];

    foreach (DB::connection('master')->select('pragma foreign_key_list('.$table.')') as $key) {
        $key = (array) $key;
        $keys[] = $key['from'].' -> '.$key['table'].'.'.$key['to'];
    }

    sort($keys);

    return $keys;
}

it('gives sessions no foreign key to users, so the ULID and bigint mismatch cannot bite', function () {
    masterStandInIsConfigured();

    expect(Artisan::call('migrate', ['--database' => 'master', '--force' => true]))->toBe(0);

    expect(masterStandInForeignKeys('sessions'))->toBe([]);

    // The control, and it is the whole value of the case: the reader does find
    // keys where the schema declares them, so an empty list above is a fact
    // about `sessions` rather than about a pragma that answers nothing. The
    // master tree is full of real keys - this one is in the same database, on
    // a table the same migration run created.
    expect(masterStandInForeignKeys('tenant_databases'))
        ->toContain('server_id -> database_servers.id')
        ->toContain('tenant_id -> tenants.id');

    // And the column types that make the key a bad idea today, named here so
    // FP-207 meets this test when it changes one of them.
    expect(Schema::connection('master')->getColumnType('sessions', 'user_id'))->toBe('integer')
        ->and(Schema::connection('master')->getColumnType('users', 'id'))->toBe('varchar');
});
