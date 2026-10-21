<?php

use Flexipos\Kernel\Schema\Support\MigrationCatalogue;
use Flexipos\Kernel\Schema\Support\MigrationTree;
use Flexipos\Kernel\Schema\Support\SourceRules;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;

/*
|--------------------------------------------------------------------------
| T-011: the settings.updated_by key, at migration level
|--------------------------------------------------------------------------
|
| AC-13 to AC-17 (the migration half), AC-20 and AC-21. The subject is one
| file of the shipped tenant tree, found in the catalogue by its name; its
| version V is its position there, never a number written here.
|
| Each case builds a private in-memory SQLite stand-in, applies files 1 to
| V - 1 in ONE migrate call, seeds, and then applies V's file alone.
| Foreign-key enforcement is set per case in this file's own stanza. The
| tenant stanza the application builds leaves it off, and changing that is
| FP-305's, not this task's.
|
| This file spawns no child process and stays out of the toolchain group.
*/

/**
 * The migration this file is about.
 */
function settingsKeyMigration(): string
{
    return '2026_10_21_000401_add_updated_by_foreign_key_to_settings_table';
}

function settingsKeyStandIn(): string
{
    return 'settings_key_stand_in';
}

/**
 * The shipped tree's files, in catalogue order.
 *
 * @return list<string>
 */
function settingsKeyPaths(): array
{
    $paths = glob(migrationTreeDirectory(MigrationTree::Tenant).DIRECTORY_SEPARATOR.'*.php') ?: [];

    sort($paths);

    return $paths;
}

/**
 * The migration's catalogue entry.
 */
function settingsKeyEntry(): MigrationCatalogue
{
    foreach (MigrationCatalogue::forTree(migrationTreeDirectory(MigrationTree::Tenant)) as $entry) {
        if ($entry->migration === settingsKeyMigration()) {
            return $entry;
        }
    }

    throw new RuntimeException('The shipped tenant tree does not hold '.settingsKeyMigration().'.');
}

function settingsKeyPath(): string
{
    return settingsKeyPaths()[settingsKeyEntry()->version - 1];
}

/**
 * A fresh stand-in carrying the tree up to V - 1.
 */
function settingsKeyBoot(bool $enforce): void
{
    DB::purge(settingsKeyStandIn());

    config()->set('database.connections.'.settingsKeyStandIn(), [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => $enforce,
    ]);

    expect(config('database.connections.'.settingsKeyStandIn().'.driver'))->toBe('sqlite');

    expect(Artisan::call('migrate', [
        '--database' => settingsKeyStandIn(),
        '--path' => array_slice(settingsKeyPaths(), 0, settingsKeyEntry()->version - 1),
        '--realpath' => true,
        '--force' => true,
    ]))->toBe(0);

    expect((int) DB::connection(settingsKeyStandIn())->scalar('pragma foreign_keys'))->toBe($enforce ? 1 : 0);
}

/**
 * Apply one migration file - V's unless told otherwise.
 */
function settingsKeyApply(?string $path = null): int
{
    return Artisan::call('migrate', [
        '--database' => settingsKeyStandIn(),
        '--path' => $path ?? settingsKeyPath(),
        '--realpath' => true,
        '--force' => true,
    ]);
}

/**
 * Apply V's file and return what it threw, or null.
 */
function settingsKeyApplyCatching(?string $path = null): ?Throwable
{
    try {
        settingsKeyApply($path);
    } catch (Throwable $thrown) {
        return $thrown;
    }

    return null;
}

/**
 * A 26-character identifier from a short stem.
 */
function settingsKeyId(string $stem): string
{
    return str_pad($stem, 26, '0');
}

function settingsKeyEmployee(string $id, ?string $deletedAt = null): void
{
    DB::connection(settingsKeyStandIn())->table('employees')->insert([
        'id' => $id,
        'code' => substr($id, 0, 20),
        'first_name' => 'Test',
        'display_name' => 'Test '.$id,
        'status' => 'active',
        'created_at' => '2026-10-21 09:00:00.000',
        'updated_at' => '2026-10-21 09:00:00.000',
        'deleted_at' => $deletedAt,
    ]);
}

function settingsKeyRegistry(string $key): void
{
    DB::connection(settingsKeyStandIn())->table('settings_registry')->insert([
        'key' => $key,
        'module' => 'test',
        'type' => 'string',
    ]);
}

function settingsKeySetting(string $id, ?string $scopeId, string $key, ?string $updatedBy): void
{
    DB::connection(settingsKeyStandIn())->table('settings')->insert([
        'id' => $id,
        'scope_type' => $scopeId === null ? 'company' : 'branch',
        'scope_id' => $scopeId,
        'key' => $key,
        'value' => '"v-'.$id.'"',
        'updated_by' => $updatedBy,
        'created_at' => '2026-10-21 09:00:00.000',
        'updated_at' => '2026-10-21 09:00:00.000',
    ]);
}

/**
 * A populated settings table: both scope kinds, an updated_by that is null,
 * one naming a live employee and one naming a soft-deleted employee.
 */
function settingsKeySeedClean(): void
{
    settingsKeyEmployee(settingsKeyId('EMPLIVE'));
    settingsKeyEmployee(settingsKeyId('EMPGONE'), '2026-10-20 09:00:00.000');
    settingsKeyRegistry('pos.receipt_footer');
    settingsKeyRegistry('pos.currency');

    settingsKeySetting(settingsKeyId('SET1'), null, 'pos.receipt_footer', settingsKeyId('EMPLIVE'));
    settingsKeySetting(settingsKeyId('SET2'), settingsKeyId('BRANCHA'), 'pos.receipt_footer', settingsKeyId('EMPGONE'));
    settingsKeySetting(settingsKeyId('SET3'), null, 'pos.currency', null);
}

/**
 * The distinctive value the D-10 assertions look for: an id that names no
 * employee. Distinctive so that "absent from the message" cannot pass by
 * accident.
 */
function settingsKeySentinel(): string
{
    return 'ZZLEAKZZ7Q9W3E5R1T8Y6U4I2O';
}

/**
 * Every row of a table, ordered by id, every column.
 *
 * @return list<array<string, mixed>>
 */
function settingsKeyRows(string $table = 'settings'): array
{
    return array_map(
        static fn ($row): array => (array) $row,
        DB::connection(settingsKeyStandIn())->select('select * from "'.$table.'" order by id')
    );
}

/**
 * The schema SQLite holds for the settings table and its indexes.
 *
 * @return list<array<string, mixed>>
 */
function settingsKeySchemaOf(string $table = 'settings'): array
{
    return array_map(
        static fn ($row): array => (array) $row,
        DB::connection(settingsKeyStandIn())->select('select type, name, sql from sqlite_master where tbl_name = ? order by name', [$table])
    );
}

/**
 * A table's foreign keys, as `column -> table.column`, sorted.
 *
 * @return list<string>
 */
function settingsKeyForeignKeys(string $table = 'settings'): array
{
    $keys = [];

    foreach (DB::connection(settingsKeyStandIn())->select('pragma foreign_key_list("'.$table.'")') as $key) {
        $key = (array) $key;
        $keys[] = $key['from'].' -> '.$key['table'].'.'.$key['to'];
    }

    sort($keys);

    return $keys;
}

/**
 * Every table whose name carries the rebuild's temporary prefix. Filtered in
 * PHP rather than with LIKE, where `_` is a wildcard.
 *
 * @return list<string>
 */
function settingsKeyTempTables(): array
{
    $names = [];

    foreach (DB::connection(settingsKeyStandIn())->select("select name from sqlite_master where type = 'table'") as $row) {
        $name = (string) ((array) $row)['name'];

        if (str_contains($name, '__temp__')) {
            $names[] = $name;
        }
    }

    return $names;
}

/**
 * The rows whose stored `scope_id_key` is not COALESCE(scope_id, '-'), or the
 * one defect "no such column".
 *
 * @return list<string>
 */
function settingsKeyScopeKeyDefects(string $table = 'settings'): array
{
    $rows = settingsKeyRows($table);

    if ($rows !== [] && ! array_key_exists('scope_id_key', $rows[0])) {
        return [$table.' has no scope_id_key column'];
    }

    $defects = [];

    foreach ($rows as $row) {
        if ($row['scope_id_key'] !== ($row['scope_id'] ?? '-')) {
            $defects[] = (string) $row['id'];
        }
    }

    return $defects;
}

/**
 * Driver-specific tokens in a migration's code.
 *
 * @return list<string>
 */
function settingsKeyDriverTokens(string $source): array
{
    $code = SourceRules::codeOnly($source);

    return array_values(array_filter(
        ['getDriverName', '->driver', "'sqlite'", "'mysql'", "'mariadb'"],
        static fn (string $token): bool => str_contains($code, $token)
    ));
}

afterEach(function () {
    DB::purge(settingsKeyStandIn());

    File::deleteDirectory(storage_path('app'.DIRECTORY_SEPARATOR.'settings-key-scratch'));
});

/*
|--------------------------------------------------------------------------
| AC-13 and AC-21 - the file's shape
|--------------------------------------------------------------------------
*/

it('is one above the highest version before it, a non-destructive contract step that only adds the key (AC-13)', function () {
    $entry = settingsKeyEntry();
    $source = (string) file_get_contents(settingsKeyPath());
    $code = SourceRules::codeOnly($source);
    $catalogue = MigrationCatalogue::forTree(migrationTreeDirectory(MigrationTree::Tenant));

    preg_match_all('/Schema::(create|table|drop\w*|rename)\s*\(\s*\'([^\']+)\'/', $code, $blocks, PREG_SET_ORDER);

    // One above the highest version that existed before this file: the files
    // that sort before it. Nothing here says it is the last file, so a later
    // migration does not redden this case (D-16).
    $before = array_values(array_filter(
        $catalogue,
        static fn (MigrationCatalogue $other): bool => strcmp($other->migration, settingsKeyMigration()) < 0
    ));

    expect($before)->not->toBe([])
        ->and($entry->version)->toBe(max(array_map(static fn (MigrationCatalogue $other): int => $other->version, $before)) + 1)
        ->and($entry->phase->value)->toBe('contract')
        ->and($entry->isDestructive)->toBeFalse()
        ->and($entry->requiresOffline)->toBeFalse()
        ->and(SourceRules::tablesCreatedIn($source))->toBe([])
        ->and(SourceRules::inspect($source, MigrationTree::Tenant))->toBe([])
        ->and(SourceRules::importsOutsideAllowedNamespaces($source))->toBe([])
        ->and(array_map(static fn (array $block): string => $block[1].' '.$block[2], $blocks))->toBe(['table settings'])
        ->and(settingsKeyDriverTokens($source))->toBe([])
        ->and(preg_match('/\btruncate\b/i', $code))->toBe(0);

    // The docblock carries what the spec asks of it.
    foreach (['settings.updated_by', 'employees.id', 'D-5', 'runs first', 'Two runs', 'tenant:migrate --all --to=', 'D-10'] as $phrase) {
        expect($source)->toContain($phrase);
    }
});

it('reports a driver check in a migration source (AC-13 control)', function () {
    expect(settingsKeyDriverTokens("<?php if (Schema::getConnection()->getDriverName() === 'sqlite') { return; }"))
        ->toBe(['getDriverName', "'sqlite'"])
        ->and(settingsKeyDriverTokens('<?php // getDriverName in a comment is not code'))->toBe([]);
});

it('declares no down(), and neither does any other file of the tree (AC-21)', function () {
    $withDown = [];

    foreach (settingsKeyPaths() as $path) {
        if (preg_match('/function\s+down\s*\(/', SourceRules::codeOnly((string) file_get_contents($path))) === 1) {
            $withDown[] = basename($path);
        }
    }

    expect(settingsKeyPaths())->toContain(settingsKeyPath())
        ->and(count(settingsKeyPaths()))->toBeGreaterThan(1)
        ->and($withDown)->toBe([]);

    // Control: the same match sees a down() when there is one.
    expect(preg_match('/function\s+down\s*\(/', SourceRules::codeOnly('<?php return new class { public function down(): void {} };')))->toBe(1);
});

/*
|--------------------------------------------------------------------------
| AC-14 - clean data
|--------------------------------------------------------------------------
*/

it('adds the key over clean data, beside the registry key (AC-14)', function () {
    settingsKeyBoot(true);
    settingsKeySeedClean();

    // Control: before, no updated_by key.
    expect(settingsKeyForeignKeys())->toBe(['key -> settings_registry.key']);

    expect(settingsKeyApply())->toBe(0)
        ->and(settingsKeyForeignKeys())->toBe(['key -> settings_registry.key', 'updated_by -> employees.id'])
        ->and(DB::connection(settingsKeyStandIn())->table('migrations')->where('migration', settingsKeyMigration())->count())->toBe(1);
});

it('adds the key over an empty settings table, and over an empty employees table when every updated_by is null (AC-14)', function () {
    settingsKeyBoot(true);

    expect(settingsKeyApply())->toBe(0)
        ->and(settingsKeyForeignKeys())->toBe(['key -> settings_registry.key', 'updated_by -> employees.id']);

    settingsKeyBoot(true);
    settingsKeyRegistry('pos.currency');
    settingsKeySetting(settingsKeyId('SET1'), null, 'pos.currency', null);
    settingsKeySetting(settingsKeyId('SET2'), settingsKeyId('BRANCHA'), 'pos.currency', null);

    expect(DB::connection(settingsKeyStandIn())->table('employees')->count())->toBe(0)
        ->and(settingsKeyApply())->toBe(0)
        ->and(settingsKeyForeignKeys())->toBe(['key -> settings_registry.key', 'updated_by -> employees.id'])
        ->and(settingsKeyRows())->toHaveCount(2);
});

/*
|--------------------------------------------------------------------------
| AC-15 - the five rebuild properties, each with a damaged control
|--------------------------------------------------------------------------
*/

it('keeps every row, the stored sentinel, the registry key and the null-scope uniqueness through the rebuild (AC-15)', function () {
    settingsKeyBoot(true);
    settingsKeySeedClean();

    $before = settingsKeyRows();

    expect(settingsKeyApply())->toBe(0);

    // (1) every row and column identical.
    expect(settingsKeyRows())->toBe($before)
        ->and($before)->toHaveCount(3);

    // (2) the stored generated column still right, both scope kinds present.
    expect(settingsKeyScopeKeyDefects())->toBe([])
        ->and(array_column(settingsKeyRows(), 'scope_id_key'))->toContain('-')
        ->and(array_column(settingsKeyRows(), 'scope_id_key'))->toContain(settingsKeyId('BRANCHA'));

    // (3) and (4) both keys reported.
    expect(settingsKeyForeignKeys())->toContain('key -> settings_registry.key')
        ->and(settingsKeyForeignKeys())->toContain('updated_by -> employees.id');

    // (5) a second company-wide row for the same key is still refused...
    expect(fn () => settingsKeySetting(settingsKeyId('SETDUP'), null, 'pos.receipt_footer', null))
        ->toThrow(QueryException::class);

    // ...while the same key at another specific scope is accepted, so (5) is
    // the sentinel's uniqueness and not a blanket refusal.
    settingsKeySetting(settingsKeyId('SET4'), settingsKeyId('BRANCHB'), 'pos.receipt_footer', null);

    expect(settingsKeyRows())->toHaveCount(4);
});

it('shows each rebuild property red against a damaged fixture (AC-15 controls)', function () {
    settingsKeyBoot(true);
    settingsKeySeedClean();

    $before = settingsKeyRows();

    expect(settingsKeyApply())->toBe(0);

    // (1) one altered row is seen.
    DB::connection(settingsKeyStandIn())->table('settings')->where('id', settingsKeyId('SET3'))->update(['value' => '"altered"']);

    expect(settingsKeyRows())->not->toBe($before);

    // (2) and (3): a copy of the table without the generated column and
    // without any key.
    DB::connection(settingsKeyStandIn())->statement('create table settings_damaged as select id, scope_id, key from settings');

    expect(settingsKeyScopeKeyDefects('settings_damaged'))->toBe(['settings_damaged has no scope_id_key column'])
        ->and(settingsKeyForeignKeys('settings_damaged'))->not->toContain('key -> settings_registry.key');
});

/*
|--------------------------------------------------------------------------
| AC-16 - the key is live when enforcement is on
|--------------------------------------------------------------------------
*/

it('refuses an unknown employee and accepts a real one once enforcement is on (AC-16)', function () {
    settingsKeyBoot(true);
    settingsKeySeedClean();

    expect(settingsKeyApply())->toBe(0);

    $count = count(settingsKeyRows());

    expect(fn () => settingsKeySetting(settingsKeyId('SETBAD'), settingsKeyId('BRANCHC'), 'pos.currency', settingsKeySentinel()))
        ->toThrow(QueryException::class, 'FOREIGN KEY constraint failed');

    expect(settingsKeyRows())->toHaveCount($count);

    settingsKeySetting(settingsKeyId('SETGOOD'), settingsKeyId('BRANCHC'), 'pos.currency', settingsKeyId('EMPLIVE'));

    expect(settingsKeyRows())->toHaveCount($count + 1);
});

it('accepts the unknown employee with enforcement off, so AC-16 can tell the two apart (AC-16 control)', function () {
    settingsKeyBoot(false);
    settingsKeySeedClean();

    expect(settingsKeyApply())->toBe(0)
        ->and(settingsKeyForeignKeys())->toContain('updated_by -> employees.id');

    settingsKeySetting(settingsKeyId('SETBAD'), settingsKeyId('BRANCHC'), 'pos.currency', settingsKeySentinel());

    expect(DB::connection(settingsKeyStandIn())->table('settings')->where('updated_by', settingsKeySentinel())->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| AC-17, migration half - dirty data fails and changes nothing, either state
|--------------------------------------------------------------------------
*/

it('refuses dirty data and leaves settings exactly as it was, with enforcement on and off (AC-17)', function () {
    foreach ([true, false] as $enforce) {
        settingsKeyBoot($enforce);
        settingsKeySeedClean();
        settingsKeySetting(settingsKeyId('SETDIRTY'), settingsKeyId('BRANCHB'), 'pos.currency', settingsKeySentinel());

        $rows = settingsKeyRows();
        $schema = settingsKeySchemaOf();

        $thrown = settingsKeyApplyCatching();

        expect($thrown)->toBeInstanceOf(RuntimeException::class)
            ->and((string) $thrown?->getMessage())->toContain('1 row(s)')
            ->and((string) $thrown?->getMessage())->toContain('settings.updated_by')
            ->and((string) $thrown?->getMessage())->not->toContain(settingsKeySentinel())
            ->and(settingsKeyRows())->toBe($rows)
            ->and(settingsKeySchemaOf())->toBe($schema)
            ->and(settingsKeyForeignKeys())->toBe(['key -> settings_registry.key'])
            ->and(settingsKeyTempTables())->toBe([])
            ->and(DB::connection(settingsKeyStandIn())->table('migrations')->where('migration', settingsKeyMigration())->count())->toBe(0);

        // Positive control: clear the value and the same file applies.
        DB::connection(settingsKeyStandIn())->table('settings')->where('id', settingsKeyId('SETDIRTY'))->update(['updated_by' => null]);

        expect(settingsKeyApply())->toBe(0)
            ->and(settingsKeyForeignKeys())->toContain('updated_by -> employees.id');
    }
});

it('counts every offending row, and names no value (AC-17, D-10)', function () {
    settingsKeyBoot(false);
    settingsKeySeedClean();
    settingsKeySetting(settingsKeyId('SETDIRTY1'), settingsKeyId('BRANCHB'), 'pos.currency', settingsKeySentinel());
    settingsKeySetting(settingsKeyId('SETDIRTY2'), settingsKeyId('BRANCHC'), 'pos.currency', strrev(settingsKeySentinel()));

    $message = (string) settingsKeyApplyCatching()?->getMessage();

    expect($message)->toContain('2 row(s)')
        ->and($message)->not->toContain(settingsKeySentinel())
        ->and($message)->not->toContain(strrev(settingsKeySentinel()));
});

it('runs the rebuild over dirty data without complaint once the check is cut out, in both enforcement states (Ruling 7, measured)', function () {
    // The reason the check is the guard on the stand-in, measured rather than
    // read from the grammar: the same file with the check cut out.
    $source = (string) file_get_contents(settingsKeyPath());
    $stripped = (string) preg_replace('/\$offending = .*?\n        }\n/s', '', $source, 1);

    // Shape 8: the copy really differs, as executed.
    expect($stripped)->not->toBe($source)
        ->and(SourceRules::codeOnly($stripped))->not->toContain('whereNotExists')
        ->and(SourceRules::codeOnly($stripped))->toContain("Schema::table('settings'");

    $directory = storage_path('app'.DIRECTORY_SEPARATOR.'settings-key-scratch');
    File::ensureDirectoryExists($directory);
    $copy = $directory.DIRECTORY_SEPARATOR.basename(settingsKeyPath());
    File::put($copy, $stripped);

    foreach ([true, false] as $enforce) {
        settingsKeyBoot($enforce);
        settingsKeySeedClean();
        settingsKeySetting(settingsKeyId('SETDIRTY'), settingsKeyId('BRANCHB'), 'pos.currency', settingsKeySentinel());

        $statements = [];
        DB::listen(static function ($query) use (&$statements): void {
            $statements[] = (string) $query->sql;
        });

        $thrown = settingsKeyApplyCatching($copy);

        Event::forget('Illuminate\Database\Events\QueryExecuted');

        // The rebuild really ran, the key is there, the unknown id was carried
        // into the keyed table, and SQLite itself now reports it as a
        // violation: nothing re-checked the existing data on the way in.
        $violations = DB::connection(settingsKeyStandIn())->select('pragma foreign_key_check("settings")');
        $rebuilt = array_values(array_filter($statements, static fn (string $sql): bool => str_contains($sql, '__temp__')));

        fwrite(STDOUT, PHP_EOL.'Ruling 7, enforcement '.($enforce ? 'on' : 'off').': '.($thrown === null ? 'applied' : 'threw '.$thrown::class)
            .', '.count($rebuilt).' rebuild statements, '.count($violations).' foreign_key_check violation(s) left in settings'.PHP_EOL);

        expect($thrown)->toBeNull()
            ->and($rebuilt)->not->toBe([])
            ->and(settingsKeyForeignKeys())->toContain('updated_by -> employees.id')
            ->and(DB::connection(settingsKeyStandIn())->table('settings')->where('updated_by', settingsKeySentinel())->count())->toBe(1)
            ->and($violations)->toHaveCount(1);
    }
});

/*
|--------------------------------------------------------------------------
| AC-20 - the comparison is exact, and the key refers to the row
|--------------------------------------------------------------------------
*/

it('counts an id differing only in letter case as unknown, and a soft-deleted employee as existing (AC-20)', function () {
    settingsKeyBoot(false);
    settingsKeyEmployee(settingsKeyId('EMPCASE'));
    settingsKeyRegistry('pos.currency');
    settingsKeySetting(settingsKeyId('SET1'), null, 'pos.currency', strtolower(settingsKeyId('EMPCASE')));

    expect((string) settingsKeyApplyCatching()?->getMessage())->toContain('1 row(s)');

    settingsKeyBoot(false);
    settingsKeyEmployee(settingsKeyId('EMPGONE'), '2026-10-20 09:00:00.000');
    settingsKeyRegistry('pos.currency');
    settingsKeySetting(settingsKeyId('SET1'), null, 'pos.currency', settingsKeyId('EMPGONE'));

    expect(settingsKeyApplyCatching())->toBeNull()
        ->and(settingsKeyForeignKeys())->toContain('updated_by -> employees.id');
});
