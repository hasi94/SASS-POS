<?php

use Flexipos\Kernel\Schema\Support\MigrationCatalogue;
use Flexipos\Kernel\Schema\Support\MigrationTree;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| QA, T-011: edges of the settings.updated_by key the dev files leave out
|--------------------------------------------------------------------------
|
| SettingsUpdatedByKeyTest proves the five properties AC-15 names. This file
| adds what the rebuild could still lose or mis-count and nothing names:
|
| - the rebuild keeps every index and every column definition, not only the
|   one unique key AC-15 (5) happens to exercise;
| - the migration alters `settings` and nothing else (AC-13), shown on the
|   `employees` table it reads;
| - the pre-check counts ROWS, not distinct ids, and counts an empty string
|   and a blank as unknown (the key would refuse them);
| - a refused run leaves nothing that changes the second answer, and the file
|   then applies once the data is clean (AC-17's retry, at migration level).
|
| The subject is found by name in the shipped tenant tree; it need not be the
| last file, later migrations may follow it. Its version V is its position. Each case
| builds a private in-memory SQLite stand-in with files 1 to V - 1 applied in
| one migrate call. The tenant stanza leaves foreign-key enforcement off and
| so do these cases: the pre-check is the guard there (Ruling 7).
*/

/**
 * The migration this file is about, found by name: later migrations may follow it.
 */
function qaEdgeEntry(): MigrationCatalogue
{
    foreach (MigrationCatalogue::forTree(migrationTreeDirectory(MigrationTree::Tenant)) as $entry) {
        if ($entry->migration === '2026_10_21_000401_add_updated_by_foreign_key_to_settings_table') {
            return $entry;
        }
    }

    throw new RuntimeException('The shipped tenant tree does not hold the settings.updated_by migration.');
}

function qaEdgeConnection(): string
{
    return 'qa_settings_edge_stand_in';
}

/**
 * The shipped tenant tree's files, in version order.
 *
 * @return list<string>
 */
function qaEdgePaths(): array
{
    $paths = glob(migrationTreeDirectory(MigrationTree::Tenant).DIRECTORY_SEPARATOR.'*.php') ?: [];

    sort($paths);

    return $paths;
}

/**
 * Boot a stand-in at V - 1, where V is the settings key's version.
 */
function qaEdgeBoot(): void
{
    DB::purge(qaEdgeConnection());

    config()->set('database.connections.'.qaEdgeConnection(), [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]);

    expect(qaEdgeEntry()->phase->value)->toBe('contract')
        ->and(Artisan::call('migrate', [
            '--database' => qaEdgeConnection(),
            '--path' => array_slice(qaEdgePaths(), 0, qaEdgeEntry()->version - 1),
            '--realpath' => true,
            '--force' => true,
        ]))->toBe(0);
}

/**
 * Apply V's file, returning what it threw or null.
 */
function qaEdgeApplyV(): ?Throwable
{
    try {
        Artisan::call('migrate', [
            '--database' => qaEdgeConnection(),
            '--path' => qaEdgePaths()[qaEdgeEntry()->version - 1],
            '--realpath' => true,
            '--force' => true,
        ]);
    } catch (Throwable $thrown) {
        return $thrown;
    }

    return null;
}

function qaEdgeId(string $stem): string
{
    return str_pad($stem, 26, '0');
}

function qaEdgeEmployee(string $stem, ?string $deletedAt = null): void
{
    DB::connection(qaEdgeConnection())->table('employees')->insert([
        'id' => qaEdgeId($stem),
        'code' => $stem,
        'first_name' => 'Test',
        'display_name' => 'Test '.$stem,
        'status' => 'active',
        'created_at' => '2026-10-21 09:00:00.000',
        'updated_at' => '2026-10-21 09:00:00.000',
        'deleted_at' => $deletedAt,
    ]);
}

function qaEdgeSetting(string $stem, ?string $scopeId, ?string $updatedBy): void
{
    DB::connection(qaEdgeConnection())->table('settings')->insert([
        'id' => qaEdgeId($stem),
        'scope_type' => $scopeId === null ? 'company' : 'branch',
        'scope_id' => $scopeId,
        'key' => 'qa.edge.'.$stem,
        'value' => '"v"',
        'updated_by' => $updatedBy,
        'created_at' => '2026-10-21 09:00:00.000',
        'updated_at' => '2026-10-21 09:00:00.000',
    ]);
}

function qaEdgeRegistry(string $stem): void
{
    DB::connection(qaEdgeConnection())->table('settings_registry')->insert([
        'key' => 'qa.edge.'.$stem,
        'module' => 'test',
        'type' => 'string',
    ]);
}

/**
 * One keyed setting, registry row included.
 */
function qaEdgeAddSetting(string $stem, ?string $scopeId, ?string $updatedBy): void
{
    qaEdgeRegistry($stem);
    qaEdgeSetting($stem, $scopeId, $updatedBy);
}

/**
 * What a table is, apart from its foreign keys: every column's definition and
 * every index's name, uniqueness and columns.
 *
 * @return array{columns: list<array<string, mixed>>, indexes: list<array<string, mixed>>}
 */
function qaEdgeShape(string $table): array
{
    $connection = DB::connection(qaEdgeConnection());

    $columns = array_map(
        static fn ($row): array => ['name' => ((array) $row)['name'], 'type' => ((array) $row)['type'], 'notnull' => ((array) $row)['notnull'], 'default' => ((array) $row)['dflt_value'], 'pk' => ((array) $row)['pk'], 'hidden' => ((array) $row)['hidden']],
        $connection->select('pragma table_xinfo("'.$table.'")')
    );

    $indexes = [];

    foreach ($connection->select('pragma index_list("'.$table.'")') as $index) {
        $index = (array) $index;
        $indexes[] = [
            'name' => $index['name'],
            'unique' => $index['unique'],
            'columns' => array_map(static fn ($c): mixed => ((array) $c)['name'], $connection->select('pragma index_info("'.$index['name'].'")')),
        ];
    }

    usort($indexes, static fn (array $a, array $b): int => strcmp((string) $a['name'], (string) $b['name']));

    return ['columns' => $columns, 'indexes' => $indexes];
}

/**
 * @return list<array<string, mixed>>
 */
function qaEdgeRows(string $table): array
{
    return array_map(static fn ($row): array => (array) $row, DB::connection(qaEdgeConnection())->select('select * from "'.$table.'" order by 1'));
}

afterEach(function () {
    DB::purge(qaEdgeConnection());
});

it('keeps every column definition and every index of settings, and leaves employees alone (AC-13, AC-15)', function () {
    qaEdgeBoot();
    qaEdgeEmployee('EMPLIVE');
    qaEdgeAddSetting('A', null, qaEdgeId('EMPLIVE'));
    qaEdgeAddSetting('B', qaEdgeId('BRANCHA'), null);

    $before = qaEdgeShape('settings');
    $employees = ['shape' => qaEdgeShape('employees'), 'rows' => qaEdgeRows('employees')];

    fwrite(STDOUT, PHP_EOL.'settings before the key: '.count($before['columns']).' columns, indexes '.implode(', ', array_column($before['indexes'], 'name')).PHP_EOL);

    // Liveness: there is something to lose. An index list of one proves less.
    expect(count($before['columns']))->toBeGreaterThan(5)
        ->and(count($before['indexes']))->toBeGreaterThanOrEqual(1)
        ->and(array_column($before['columns'], 'name'))->toContain('scope_id_key');

    expect(qaEdgeApplyV())->toBeNull()
        ->and(qaEdgeShape('settings'))->toBe($before)
        ->and(['shape' => qaEdgeShape('employees'), 'rows' => qaEdgeRows('employees')])->toBe($employees);
});

it('counts rows rather than distinct ids, and counts an empty string and a blank as unknown (AC-17, AC-20)', function () {
    qaEdgeBoot();
    qaEdgeEmployee('EMPLIVE');
    qaEdgeEmployee('EMPGONE', '2026-10-20 09:00:00.000');

    qaEdgeAddSetting('CLEANLIVE', null, qaEdgeId('EMPLIVE'));
    qaEdgeAddSetting('CLEANGONE', qaEdgeId('BRANCHA'), qaEdgeId('EMPGONE'));
    qaEdgeAddSetting('CLEANNULL', qaEdgeId('BRANCHB'), null);

    // Four unknown rows: the same unknown id twice, an empty string, a blank.
    qaEdgeAddSetting('DIRTYONE', qaEdgeId('BRANCHC'), qaEdgeId('NOBODY'));
    qaEdgeAddSetting('DIRTYTWO', qaEdgeId('BRANCHD'), qaEdgeId('NOBODY'));
    qaEdgeAddSetting('DIRTYEMPTY', qaEdgeId('BRANCHE'), '');
    qaEdgeAddSetting('DIRTYBLANK', qaEdgeId('BRANCHF'), ' ');

    $rows = qaEdgeRows('settings');
    $thrown = qaEdgeApplyV();

    expect($thrown)->toBeInstanceOf(RuntimeException::class)
        ->and((string) $thrown?->getMessage())->toContain('4 row(s)')
        ->and((string) $thrown?->getMessage())->not->toContain(qaEdgeId('NOBODY'))
        ->and(qaEdgeRows('settings'))->toBe($rows);
});

it('gives the same answer on a second refused run, then applies once the data is clean (AC-17)', function () {
    qaEdgeBoot();
    qaEdgeEmployee('EMPLIVE');
    qaEdgeAddSetting('CLEAN', null, qaEdgeId('EMPLIVE'));
    qaEdgeAddSetting('DIRTY', qaEdgeId('BRANCHA'), qaEdgeId('NOBODY'));

    $subject = qaEdgeEntry()->migration;

    $first = qaEdgeApplyV();
    $second = qaEdgeApplyV();

    expect($first)->toBeInstanceOf(RuntimeException::class)
        ->and((string) $second?->getMessage())->toBe((string) $first?->getMessage())
        ->and(DB::connection(qaEdgeConnection())->table('migrations')->where('migration', $subject)->count())->toBe(0);

    DB::connection(qaEdgeConnection())->table('settings')->where('id', qaEdgeId('DIRTY'))->update(['updated_by' => qaEdgeId('EMPLIVE')]);

    $rows = qaEdgeRows('settings');

    expect(qaEdgeApplyV())->toBeNull()
        ->and(DB::connection(qaEdgeConnection())->table('migrations')->where('migration', $subject)->count())->toBe(1)
        ->and(qaEdgeRows('settings'))->toBe($rows);
});
