<?php

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| QA, T-008, AC-62: the signature instrument, built and run on the stand-in
|--------------------------------------------------------------------------
|
| AC-62 asks for a normalised signature per table (column name, nullability,
| data-type family, key columns, index columns, foreign key target), written to
| the test output with the engine and version, a comparison function that
| accepts two equal signatures, and a control where one side has one extra
| index. The plan states the reduction is "built and run"; nothing in the
| branch contained it, so it is built here.
|
| **What this is and is not.** It is the instrument. It was run on the
| in-memory SQLite stand-in, whose engine and version are printed beside the
| signature count, and it takes any Connection, so the engine test that
| eventually runs it against MariaDB 10.4 passes that connection and changes
| nothing else. It is **not** a MariaDB signature and no criterion is answered
| by it: AC-62 is [E], the engine run is NOT VERIFIED behind ENGINE_HOLD (G-1),
| and the MariaDB-versus-MySQL comparison is deferred (D-24).
|
| The normalisation, as AC-62 requires it to be listed in the test:
|   - every integer width (tinyint to bigint, int, integer) is one family;
|   - char, varchar and string are one family; text, mediumtext, longtext and
|     json are one family (MariaDB stores JSON as longtext);
|   - datetime and timestamp are one family; decimal and numeric are one family;
|   - collations and character sets are not part of the signature (they are
|     the engine-specific names the criterion says to normalise away);
|   - index and foreign-key names are not part of the signature, only columns
|     and targets, so a name an engine generates differently is not a diff.
*/

/**
 * @return array<string, string>
 */
function qaFamilyMap(): array
{
    return [
        'tinyint' => 'integer', 'smallint' => 'integer', 'mediumint' => 'integer', 'int' => 'integer',
        'integer' => 'integer', 'bigint' => 'integer', 'boolean' => 'integer', 'bool' => 'integer',
        'char' => 'string', 'varchar' => 'string', 'string' => 'string',
        'text' => 'text', 'mediumtext' => 'text', 'longtext' => 'text', 'tinytext' => 'text', 'json' => 'text',
        'datetime' => 'datetime', 'timestamp' => 'datetime',
        'decimal' => 'decimal', 'numeric' => 'decimal',
        'float' => 'float', 'double' => 'float', 'real' => 'float',
        'blob' => 'binary', 'varbinary' => 'binary', 'binary' => 'binary', 'longblob' => 'binary',
    ];
}

/**
 * The signature of one table.
 *
 * @return array{columns: list<string>, indexes: list<string>, foreign_keys: list<string>}
 */
function qaSignatureOf(Connection $connection, string $table): array
{
    $schema = Schema::connection((string) $connection->getName());
    $families = qaFamilyMap();

    $columns = [];

    foreach ($schema->getColumns($table) as $column) {
        $type = strtolower((string) $column['type_name']);
        $columns[] = $column['name'].'|'.($column['nullable'] ? 'null' : 'not null').'|'.($families[$type] ?? 'other:'.$type);
    }

    $indexes = [];

    foreach ($schema->getIndexes($table) as $index) {
        $indexes[] = ($index['primary'] ? 'primary' : ($index['unique'] ? 'unique' : 'index')).':'.implode(',', $index['columns']);
    }

    $foreign = [];

    foreach ($schema->getForeignKeys($table) as $key) {
        $foreign[] = implode(',', $key['columns']).'->'.$key['foreign_table'].'.'.implode(',', $key['foreign_columns']);
    }

    sort($columns);
    sort($indexes);
    sort($foreign);

    return ['columns' => $columns, 'indexes' => $indexes, 'foreign_keys' => $foreign];
}

/**
 * The signature of every table on a connection except the framework's own.
 *
 * @return array<string, array{columns: list<string>, indexes: list<string>, foreign_keys: list<string>}>
 */
function qaSignaturesOf(Connection $connection): array
{
    $signatures = [];

    foreach (Schema::connection((string) $connection->getName())->getTables() as $table) {
        $name = (string) $table['name'];

        if (str_starts_with($name, 'sqlite_')) {
            continue;
        }

        $signatures[$name] = qaSignatureOf($connection, $name);
    }

    ksort($signatures);

    return $signatures;
}

/**
 * Every difference between two sets of signatures, as readable lines.
 *
 * @param  array<string, array{columns: list<string>, indexes: list<string>, foreign_keys: list<string>}>  $left
 * @param  array<string, array{columns: list<string>, indexes: list<string>, foreign_keys: list<string>}>  $right
 * @return list<string>
 */
function qaCompareSignatures(array $left, array $right): array
{
    $differences = [];

    foreach (array_unique(array_merge(array_keys($left), array_keys($right))) as $table) {
        if (! isset($left[$table]) || ! isset($right[$table])) {
            $differences[] = $table.': present on one side only';

            continue;
        }

        foreach (['columns', 'indexes', 'foreign_keys'] as $part) {
            foreach (array_diff($left[$table][$part], $right[$table][$part]) as $only) {
                $differences[] = $table.' '.$part.': left only '.$only;
            }

            foreach (array_diff($right[$table][$part], $left[$table][$part]) as $only) {
                $differences[] = $table.' '.$part.': right only '.$only;
            }
        }
    }

    return $differences;
}

/**
 * A fresh in-memory SQLite connection with the given tree applied.
 */
function qaSignatureConnection(string $name, string $path): Connection
{
    config()->set('database.connections.'.$name, [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);

    expect(config('database.connections.'.$name.'.driver'))->toBe('sqlite');

    expect(Artisan::call('migrate', ['--database' => $name, '--path' => $path, '--force' => true]))->toBe(0);

    return DB::connection($name);
}

it('writes a signature for every master and baseline table, and accepts two equal signatures', function () {
    $masterOne = qaSignatureConnection('qa_signature_master_one', 'database/migrations');
    $masterTwo = qaSignatureConnection('qa_signature_master_two', 'database/migrations');
    $tenantOne = qaSignatureConnection('qa_signature_tenant_one', (string) config('schema.tenant_migration_path'));
    $tenantTwo = qaSignatureConnection('qa_signature_tenant_two', (string) config('schema.tenant_migration_path'));

    $master = qaSignaturesOf($masterOne);
    $tenant = qaSignaturesOf($tenantOne);

    // 29 = AC-1's seventeen application tables, T-009 step 3's four more
    // (login_history, refresh_tokens, security_events, signing_keys; AC-50) and
    // the eight system tables;
    // The tenant side is the whole shipped tree plus the framework's
    // `migrations`, derived rather than written as a literal: T-010 took it
    // from AC-23's six to fourteen, and a count written here would have to be
    // edited by every task that ships a tenant table (T-010 Ruling 2).
    expect($master)->toHaveCount(29)
        ->and($tenant)->toHaveCount(count(tenantTreeTables()) + 1)
        ->and(array_keys($master))->toContain('tenants', 'tenant_databases', 'users', 'sessions', 'migrations')
        ->and(array_keys($tenant))->toContain('settings', 'outbox_events', 'schema_meta', 'migrations');

    // The test output names the engine and its version, as AC-62 requires.
    $version = (string) $masterOne->selectOne('select sqlite_version() as v')->v;

    fwrite(STDERR, sprintf(
        "\nSIGNATURE-INSTRUMENT engine=sqlite version=%s master_tables=%d tenant_tables=%d (stand-in, not MariaDB)\n",
        $version,
        count($master),
        count($tenant)
    ));

    // Two separate applications of the same tree reduce to the same thing.
    expect(qaCompareSignatures($master, qaSignaturesOf($masterTwo)))->toBe([])
        ->and(qaCompareSignatures($tenant, qaSignaturesOf($tenantTwo)))->toBe([]);
});

it('reports a pair where one side has one extra index, and normalises widths and families', function () {
    // The control the criterion names. Without it, "no differences" is also
    // what a comparison that compares nothing returns.
    $left = qaSignatureConnection('qa_signature_control_left', (string) config('schema.tenant_migration_path'));
    $right = qaSignatureConnection('qa_signature_control_right', (string) config('schema.tenant_migration_path'));

    Schema::connection('qa_signature_control_right')->table('settings', function ($table) {
        $table->index('updated_by', 'qa_extra_index');
    });

    $differences = qaCompareSignatures(qaSignaturesOf($left), qaSignaturesOf($right));

    expect($differences)->toBe(['settings indexes: right only index:updated_by']);

    // And the normalisation does its job from the other side: widths of one
    // family are not a difference.
    expect(qaFamilyMap()['tinyint'])->toBe(qaFamilyMap()['bigint'])
        ->and(qaFamilyMap()['json'])->toBe(qaFamilyMap()['longtext'])
        ->and(qaFamilyMap()['char'])->not->toBe(qaFamilyMap()['text']);
});
