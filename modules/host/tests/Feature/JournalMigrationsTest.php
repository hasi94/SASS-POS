<?php

use Flexipos\Kernel\Schema\Exceptions\MigrationRunRefused;
use Flexipos\Kernel\Schema\Features\PlanMigrationRun\PlanMigrationRun;
use Flexipos\Kernel\Schema\Features\PlanMigrationRun\PlanMigrationRunHandler;
use Flexipos\Kernel\Schema\Support\MigrationPhase;
use Flexipos\Kernel\Schema\Support\MigrationSet;
use Flexipos\Kernel\Schema\Support\MigrationTree;
use Flexipos\Kernel\Schema\Support\SourceRules;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/*
|--------------------------------------------------------------------------
| The journal migrations: two expand files, one backfill file and one
| contract file (T-033 plan step A3; AC-75 to AC-78, AC-81)
|--------------------------------------------------------------------------
|
| The same shape as OwedAccountColumnsMigrationsTest: the files before the
| first journal file are applied once to an in-memory database written out
| with VACUUM INTO, every case copies that file, and the step under test runs
| through the migrator (which is the subject). Each version is found by name,
| never written as a literal (P-2).
|
| - the contract file refuses an orphan `retained_earnings_entry_id` naming
|   `fiscal_years.retained_earnings_entry_id` and a COUNT, never a value, and
|   adds no key when it refuses (AC-76, red-first);
| - when clean it adds the key, keeps every year and every period (the
|   SQLite rebuild of `fiscal_years`, a CASCADE parent, must not take its
|   periods with it) and every older key, enforces the new one, and is a
|   no-op when re-run (AC-76);
| - every T-033 catalogue row is not destructive, no file changes or tightens
|   an existing column, the phases are expand, expand, backfill, contract, and
|   the planner plans the contract file only once every tenant is at the
|   version before it (AC-77);
| - on tenants with and without data the three expand and backfill files add
|   two empty tables and exactly one sequence row, and the contract file
|   changes nothing else (AC-78).
|
| No engine: SQLite files under storage/app, the driver asserted at open.
*/

require_once __DIR__.'/../../../../packages/kernel/tests/Support/migrate-tenant-fixture.php';

const JMIG_ENTRIES = '2026_11_16_003301_create_journal_entries_table';

const JMIG_LINES = '2026_11_16_003302_create_journal_lines_table';

const JMIG_SEQUENCE = '2026_11_16_003303_seed_journal_entry_number_sequence';

const JMIG_CONTRACT = '2026_11_16_003304_add_retained_earnings_entry_foreign_key';

const JMIG_CONNECTION = 'jmig_case';

const JMIG_NOW = '2026-11-16 09:00:00.000';

/** A 26-character id no journal entry holds. */
const JMIG_SENTINEL = 'ZZLEAKZZ7Q9W3E5R1T8Y6U4I2O';

function jmigSet(): MigrationSet
{
    return MigrationSet::baseline(migrationTreeDirectory(MigrationTree::Tenant));
}

function jmigVersion(string $migration): int
{
    $version = jmigSet()->versionOf($migration);

    if ($version === null) {
        throw new RuntimeException('The shipped tenant tree does not hold '.$migration.'.');
    }

    return $version;
}

function jmigTemplatePath(): string
{
    return storage_path('app'.DIRECTORY_SEPARATOR.'jmig-template.sqlite');
}

function jmigCasePath(): string
{
    return storage_path('app'.DIRECTORY_SEPARATOR.'jmig-case.sqlite');
}

/**
 * Every file before the first journal file, in one migrate call, into memory,
 * then out to a file.
 */
function jmigBuildTemplate(): void
{
    $name = 'jmig_template_memory';

    config()->set('database.connections.'.$name, ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);

    expect(config('database.connections.'.$name.'.driver'))->toBe('sqlite');

    $paths = array_slice(jmigSet()->paths(), 0, jmigVersion(JMIG_ENTRIES) - 1);

    expect(Artisan::call('migrate', ['--database' => $name, '--path' => $paths, '--realpath' => true, '--force' => true]))->toBe(0);

    File::delete(jmigTemplatePath());

    DB::connection($name)->statement('vacuum into '.DB::connection($name)->getPdo()->quote(jmigTemplatePath()));

    DB::purge($name);
}

/**
 * A fresh copy of the before-journal database, with keys enforced.
 */
function jmigOpen(): Connection
{
    DB::purge(JMIG_CONNECTION);

    File::delete(jmigCasePath());
    File::copy(jmigTemplatePath(), jmigCasePath());

    config()->set('database.connections.'.JMIG_CONNECTION, [
        'driver' => 'sqlite',
        'database' => jmigCasePath(),
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);

    expect(config('database.connections.'.JMIG_CONNECTION.'.driver'))->toBe('sqlite');

    /** @var Connection $connection */
    $connection = DB::connection(JMIG_CONNECTION);

    $connection->statement('pragma synchronous = off');

    return $connection;
}

function jmigApply(string $migration): int
{
    return Artisan::call('migrate', [
        '--database' => JMIG_CONNECTION,
        '--path' => [jmigSet()->pathFor(jmigVersion($migration))],
        '--realpath' => true,
        '--force' => true,
    ]);
}

function jmigApplyExpand(): void
{
    foreach ([JMIG_ENTRIES, JMIG_LINES, JMIG_SEQUENCE] as $migration) {
        expect(jmigApply($migration))->toBe(0);
    }
}

/**
 * Call one migration's `up()` a second time directly: the migrator would skip
 * a recorded file, and "re-run" is the thing under test.
 */
function jmigRunUpAgain(string $migration): void
{
    $migrationObject = require (string) jmigSet()->pathFor(jmigVersion($migration));
    $previous = DB::getDefaultConnection();

    DB::setDefaultConnection(JMIG_CONNECTION);

    try {
        $migrationObject->up();
    } finally {
        DB::setDefaultConnection($previous);
    }
}

function jmigRefusal(string $migration): ?string
{
    try {
        jmigApply($migration);
    } catch (Throwable $thrown) {
        return $thrown->getMessage();
    }

    return null;
}

/**
 * The foreign keys of one table as `from -> table.to`, sorted.
 *
 * @return list<string>
 */
function jmigTableKeys(Connection $c, string $table): array
{
    $keys = [];

    foreach ($c->select('pragma foreign_key_list("'.$table.'")') as $key) {
        $key = (array) $key;
        $keys[] = $key['from'].' -> '.$key['table'].'.'.$key['to'];
    }

    sort($keys);

    return $keys;
}

/**
 * The one fiscal year the shipped seed migration put in the template.
 */
function jmigYearId(Connection $c): string
{
    return (string) $c->table('fiscal_years')->orderBy('start_date')->value('id');
}

/**
 * One raw `journal_entries` row, balanced and posted, in the seeded year's
 * first period. Returns its id.
 */
function jmigSeedEntry(Connection $c, string $stem = 'ENTRY1'): string
{
    $id = strtoupper(str_pad(substr($stem, 0, 26), 26, '0'));

    $c->table('journal_entries')->insert([
        'id' => $id, 'number' => 'JE-'.$stem, 'entry_date' => '2026-06-15',
        'period_id' => (string) $c->table('fiscal_periods')->orderBy('start_date')->value('id'),
        'source_type' => 'manual', 'status' => 'posted', 'total_debit' => '10.0000', 'total_credit' => '10.0000',
        'payload_fingerprint' => str_repeat('a', 64), 'posted_at' => JMIG_NOW, 'created_at' => JMIG_NOW, 'updated_at' => JMIG_NOW,
    ]);

    return $id;
}

/**
 * Every row and the stored DDL of the two tables a rebuild touches, and any
 * temp table a half-finished rebuild left behind.
 *
 * @return array<string, string>
 */
function jmigSnapshot(Connection $c): array
{
    $snapshot = [];

    foreach (['fiscal_years', 'fiscal_periods'] as $table) {
        $snapshot[$table.' rows'] = json_encode($c->select('select * from "'.$table.'" order by id'), JSON_THROW_ON_ERROR);
        $snapshot[$table.' ddl'] = (string) ($c->selectOne('select sql from sqlite_master where type = ? and name = ?', ['table', $table])->sql ?? '');
    }

    $snapshot['temp tables'] = json_encode(array_column(
        $c->select("select name from sqlite_master where name like '%__temp__%' order by name"),
        'name'
    ), JSON_THROW_ON_ERROR);

    return $snapshot;
}

/**
 * Every row of every application table, by table name. `migrations` is the
 * migrator's own bookkeeping and is left out.
 *
 * @return array<string, string>
 */
function jmigAllRows(Connection $c): array
{
    $rows = [];

    foreach ($c->select("select name from sqlite_master where type = 'table' and name not like 'sqlite_%' and name <> 'migrations' order by name") as $table) {
        $name = (string) $table->name;
        $rows[$name] = json_encode($c->select('select * from "'.$name.'" order by rowid'), JSON_THROW_ON_ERROR);
    }

    return $rows;
}

beforeEach(function () {
    jmigBuildTemplate();
});

afterEach(function () {
    DB::purge(JMIG_CONNECTION);

    File::delete(jmigCasePath());
    File::delete(jmigTemplatePath());
});

/*
|--------------------------------------------------------------------------
| Contract (AC-76)
|--------------------------------------------------------------------------
*/

it('contract: refuses an orphan naming fiscal_years.retained_earnings_entry_id and a COUNT, never a value, and adds no key (AC-76 red-first)', function () {
    $c = jmigOpen();
    jmigApplyExpand();

    $year = jmigYearId($c);
    $c->table('fiscal_years')->where('id', $year)->update(['retained_earnings_entry_id' => JMIG_SENTINEL]);

    $before = jmigSnapshot($c);
    $message = jmigRefusal(JMIG_CONTRACT);

    expect($message)->not->toBeNull()
        ->and($message)->toContain('fiscal_years.retained_earnings_entry_id')
        ->and($message)->toContain('1 row(s)')
        ->and($message)->not->toContain(JMIG_SENTINEL)
        ->and(jmigSnapshot($c))->toBe($before)
        ->and(jmigTableKeys($c, 'fiscal_years'))->not->toContain('retained_earnings_entry_id -> journal_entries.id');

    // A lower-case spelling of a real id is also an orphan: the comparison is exact.
    $entry = jmigSeedEntry($c);
    $c->table('fiscal_years')->where('id', $year)->update(['retained_earnings_entry_id' => strtolower($entry)]);

    expect(jmigRefusal(JMIG_CONTRACT))->toContain('fiscal_years.retained_earnings_entry_id: 1 row(s)');
});

it('contract: when clean it adds the key, keeps every year, period and older key through the rebuild, and enforces it (AC-76)', function () {
    $c = jmigOpen();
    jmigApplyExpand();

    $year = jmigYearId($c);
    $entry = jmigSeedEntry($c);
    $c->table('fiscal_years')->where('id', $year)->update(['retained_earnings_entry_id' => $entry]);

    $yearsBefore = $c->table('fiscal_years')->count();
    $periodsBefore = $c->table('fiscal_periods')->count();
    $yearKeysBefore = jmigTableKeys($c, 'fiscal_years');
    $periodKeysBefore = jmigTableKeys($c, 'fiscal_periods');

    // The seed put a year with twelve periods in the template: the rebuild below has something to take with it.
    expect($yearsBefore)->toBeGreaterThanOrEqual(1)
        ->and($periodsBefore)->toBeGreaterThanOrEqual(12);

    expect(jmigApply(JMIG_CONTRACT))->toBe(0);

    // The new key, and every old key still there, and nothing else added.
    expect(jmigTableKeys($c, 'fiscal_years'))->toContain('retained_earnings_entry_id -> journal_entries.id')
        ->and(array_values(array_diff(jmigTableKeys($c, 'fiscal_years'), $yearKeysBefore)))->toBe(['retained_earnings_entry_id -> journal_entries.id'])
        ->and(array_values(array_diff($yearKeysBefore, jmigTableKeys($c, 'fiscal_years'))))->toBe([])
        ->and(jmigTableKeys($c, 'fiscal_periods'))->toBe($periodKeysBefore)
        // `fiscal_years` is a CASCADE parent: the rebuild must not have taken its periods with it.
        ->and($c->table('fiscal_years')->count())->toBe($yearsBefore)
        ->and($c->table('fiscal_periods')->count())->toBe($periodsBefore)
        ->and($c->table('fiscal_years')->where('id', $year)->value('retained_earnings_entry_id'))->toBe($entry);

    // The rebuild kept the unique keys on year name and start date.
    $dup = static fn (string $id, string $name, string $start): array => [
        'id' => str_pad($id, 26, 'X'), 'created_at' => JMIG_NOW, 'updated_at' => JMIG_NOW,
        'name' => $name, 'start_date' => $start, 'end_date' => '2099-12-31',
    ];
    $existing = $c->table('fiscal_years')->where('id', $year)->first();

    expect(fn () => $c->table('fiscal_years')->insert($dup('DUP1', 'Other name', (string) $existing->start_date)))->toThrow(QueryException::class)
        ->and(fn () => $c->table('fiscal_years')->insert($dup('DUP2', (string) $existing->name, '2098-01-01')))->toThrow(QueryException::class);

    // The key is enforced, and null is accepted.
    expect(fn () => $c->table('fiscal_years')->where('id', $year)->update(['retained_earnings_entry_id' => JMIG_SENTINEL]))
        ->toThrow(QueryException::class);

    $c->table('fiscal_years')->where('id', $year)->update(['retained_earnings_entry_id' => null]);

    expect($c->table('fiscal_years')->where('id', $year)->value('retained_earnings_entry_id'))->toBeNull();
});

it('contract: a re-run is a no-op and leaves exactly one key on the column (AC-76)', function () {
    $c = jmigOpen();
    jmigApplyExpand();
    jmigApply(JMIG_CONTRACT);

    $before = jmigSnapshot($c);

    jmigRunUpAgain(JMIG_CONTRACT);

    expect(jmigSnapshot($c))->toBe($before)
        ->and(array_count_values(jmigTableKeys($c, 'fiscal_years'))['retained_earnings_entry_id -> journal_entries.id'])->toBe(1);
});

/*
|--------------------------------------------------------------------------
| The catalogue rows, the statics and the planner (AC-77, mutation S1)
|--------------------------------------------------------------------------
*/

/**
 * The code of one migration file, comments and docblocks removed.
 */
function jmigCode(string $file): string
{
    return SourceRules::codeOnly((string) file_get_contents($file));
}

/**
 * Every statement that changes or tightens an existing column, in a code
 * string: a change call, or a nullable(false) spelling.
 */
function jmigTightens(string $code): int
{
    return preg_match_all('/->\s*change\s*\(|->\s*nullable\s*\(\s*false\s*\)|\balter\s+table\b[^;\'"]*\b(?:modify|change)\b/i', $code);
}

/**
 * The T-033 migration files, found by their shared date and task prefix.
 *
 * @return list<string>
 */
function jmigFiles(): array
{
    $directory = migrationTreeDirectory(MigrationTree::Tenant);

    return glob(rtrim($directory, '/\\').DIRECTORY_SEPARATOR.'2026_11_16_0033*.php') ?: [];
}

it('AC-77: every T-033 catalogue row is not destructive, and the phases are expand, expand, backfill, contract', function () {
    $files = jmigFiles();

    expect($files)->toHaveCount(4);

    foreach ($files as $file) {
        $entry = jmigSet()->entryFor(jmigVersion(basename($file, '.php')));

        expect($entry->isDestructive)->toBeFalse(basename($file))
            ->and($entry->requiresOffline)->toBeFalse(basename($file));
    }

    expect(jmigSet()->entryFor(jmigVersion(JMIG_ENTRIES))->phase)->toBe(MigrationPhase::Expand)
        ->and(jmigSet()->entryFor(jmigVersion(JMIG_LINES))->phase)->toBe(MigrationPhase::Expand)
        ->and(jmigSet()->entryFor(jmigVersion(JMIG_SEQUENCE))->phase)->toBe(MigrationPhase::Backfill)
        ->and(jmigSet()->entryFor(jmigVersion(JMIG_CONTRACT))->phase)->toBe(MigrationPhase::Contract)
        // Consecutive, and the contract file is the top of the shipped tree.
        ->and(jmigVersion(JMIG_LINES))->toBe(jmigVersion(JMIG_ENTRIES) + 1)
        ->and(jmigVersion(JMIG_SEQUENCE))->toBe(jmigVersion(JMIG_LINES) + 1)
        ->and(jmigVersion(JMIG_CONTRACT))->toBe(jmigVersion(JMIG_SEQUENCE) + 1)
        ->and(jmigSet()->highestVersion())->toBe(jmigVersion(JMIG_CONTRACT));
});

it('AC-77 and S1: no T-033 file changes or tightens a column, and a contract written as a change is reported', function () {
    foreach (jmigFiles() as $file) {
        expect(jmigTightens(jmigCode($file)))->toBe(0, basename($file));
    }

    // Mutation S1: the contract file rewritten with a change call is caught by the same scan.
    $real = jmigCode((string) jmigSet()->pathFor(jmigVersion(JMIG_CONTRACT)));
    $mutated = str_replace('->on(\'journal_entries\');', '->on(\'journal_entries\'); $blueprint->string(\'x\')->nullable(false)->change();', $real);

    expect($mutated)->not->toBe($real)
        ->and(jmigTightens($mutated))->toBeGreaterThan(0)
        ->and(jmigTightens("<?php\n\$table->char('retained_earnings_entry_id', 26)->change();"))->toBe(1);
});

it('AC-77: the expand files hold no key onto an existing table, and the contract file holds the key', function () {
    // Every key an expand file declares is on its own new table or onto a table that existed before the task.
    $keyOrChange = '/->\s*(?:change)\s*\(/';

    foreach ([JMIG_ENTRIES, JMIG_LINES, JMIG_SEQUENCE] as $migration) {
        expect(preg_match($keyOrChange, jmigCode((string) jmigSet()->pathFor(jmigVersion($migration)))))->toBe(0, $migration);
    }

    // The key into `journal_entries` from an existing table is not in an expand file: it is the contract file's.
    foreach ([JMIG_ENTRIES, JMIG_LINES, JMIG_SEQUENCE] as $migration) {
        expect(jmigCode((string) jmigSet()->pathFor(jmigVersion($migration))))->not->toContain("Schema::table('fiscal_years'");
    }

    // Control: the contract file legitimately holds a key call, so the same scan reports it.
    expect(preg_match('/->\s*foreign\s*\(/', jmigCode((string) jmigSet()->pathFor(jmigVersion(JMIG_CONTRACT)))))->toBe(1);
});

it('AC-77: the planner plans the contract file only once every tenant is at the version before it', function () {
    migrateFixtureBoot();

    try {
        $set = jmigSet();
        $v = jmigVersion(JMIG_CONTRACT);

        runFixturePublish($set);

        $server = masterMappingServer('jmig-server');
        runFixtureTenant('jmig-behind', $server, 'active', $v - 2, 10);
        runFixtureTenant('jmig-ready', $server, 'active', $v - 1, 20);

        $refused = null;

        try {
            app(PlanMigrationRunHandler::class)->handle(new PlanMigrationRun(set: $set, targetVersion: null));
        } catch (MigrationRunRefused $thrown) {
            $refused = $thrown->getMessage();
        }

        // One tenant two versions behind blocks the contract step, which is named by file and version.
        expect($refused)->not->toBeNull()
            ->and($refused)->toContain('['.JMIG_CONTRACT.'] (version '.$v.')')
            ->and($refused)->toContain(migrateFixtureId('jmig-behind'))
            ->and(runFixtureRunRows())->toBe([]);

        // The remedy's other half: move the straggler to the version before, and the plan is written.
        DB::connection('master')->table('tenant_databases')->where('tenant_id', migrateFixtureId('jmig-behind'))->update(['schema_version' => $v - 1]);

        app(PlanMigrationRunHandler::class)->handle(new PlanMigrationRun(set: $set, targetVersion: null));

        expect(runFixtureRunRows())->toHaveCount(1)
            ->and(array_values(array_unique(array_map(static fn (array $job): int => (int) $job['to_version'], runFixtureJobRows()))))->toBe([$v]);
    } finally {
        migrateFixtureTearDown();
    }
});

/*
|--------------------------------------------------------------------------
| AC-78 - tables empty, one sequence row added, nothing else changed
|--------------------------------------------------------------------------
*/

it('AC-78: on a tenant with no data and on one with documents, the journal tables are empty, one sequence row is added and nothing else changes', function () {
    foreach (['empty', 'with data'] as $variant) {
        $c = jmigOpen();

        if ($variant === 'with data') {
            $c->table('number_sequences')->insert([
                'id' => 'SEQ1'.str_repeat('0', 22), 'branch_id' => null, 'document_type' => 'sale', 'prefix' => 'S',
                'next_number' => 7, 'padding' => 6, 'suffix' => null, 'reset_period' => 'never', 'last_reset_at' => null,
                'created_at' => JMIG_NOW, 'updated_at' => JMIG_NOW,
            ]);
        }

        $before = jmigAllRows($c);

        jmigApplyExpand();
        expect(jmigApply(JMIG_CONTRACT))->toBe(0);

        $after = jmigAllRows($c);

        expect(array_keys($after))->toEqualCanonicalizing([...array_keys($before), 'journal_entries', 'journal_lines'])
            ->and($c->table('journal_entries')->count())->toBe(0)
            ->and($c->table('journal_lines')->count())->toBe(0);

        foreach ($before as $table => $rows) {
            if ($table === 'number_sequences') {
                continue;
            }

            expect($after[$table])->toBe($rows, "[{$variant}] {$table} changed");
        }

        // The sequence table gained exactly the journal_entry row; every row it had is as it was.
        $sequences = $c->table('number_sequences')->orderBy('document_type')->get()->all();
        $added = array_values(array_filter($sequences, static fn (object $row): bool => $row->document_type === 'journal_entry'));

        expect($added)->toHaveCount(1)
            ->and($c->table('number_sequences')->count())->toBe(count(json_decode($before['number_sequences'], true)) + 1)
            ->and($added[0]->prefix)->toBe('JE-')
            ->and((int) $added[0]->next_number)->toBe(1);

        foreach (json_decode($before['number_sequences'], true) as $row) {
            expect($c->table('number_sequences')->where('id', $row['id'])->first())->toEqual((object) $row);
        }
    }
});
