<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| QA, T-008: the master's declared shape, read from the stand-in
|--------------------------------------------------------------------------
|
| **This is not evidence for AC-2 to AC-9 and must not be recorded as such.**
| Those are [E] criteria: types, collations and refusals are the engine's to
| answer, and while ENGINE_HOLD is set they are NOT VERIFIED (G-1). This file is
| the half that needs no engine and that nothing else in the branch asserts:
| the column and key **names** each criterion lists, and the foreign keys the
| migrations declare. A migration that dropped `tenant_databases.status_reason`
| or `database_servers.tls_mode`, or forgot a unique key, would pass every
| shipped case and be found only on the engine, after the hold lifts.
|
| The connection is asserted to be the in-memory stand-in before anything
| migrates, for the reason MasterSchemaBuildsTest gives. SQLite does not
| enforce the foreign keys (the master stanza names no
| `foreign_key_constraints`), which is why they are read as declarations and
| why no refusal is asserted here.
*/

beforeEach(function () {
    expect(config('database.connections.master.driver'))->toBe('sqlite')
        ->and(config('database.connections.master.database'))->toBe(':memory:');

    expect(Artisan::call('migrate', ['--database' => 'master', '--force' => true]))->toBe(0);
});

/**
 * @return list<string>
 */
function qaColumns(string $table): array
{
    $names = array_map(
        static fn (array $column): string => (string) $column['name'],
        Schema::connection('master')->getColumns($table)
    );

    sort($names);

    return $names;
}

/**
 * Index column lists, keyed by a readable "unique:a,b" / "index:a,b" label.
 *
 * @return list<string>
 */
function qaIndexes(string $table): array
{
    $labels = [];

    foreach (Schema::connection('master')->getIndexes($table) as $index) {
        $labels[] = ($index['primary'] ? 'primary' : ($index['unique'] ? 'unique' : 'index')).':'.implode(',', $index['columns']);
    }

    sort($labels);

    return $labels;
}

/**
 * Foreign keys as "local->table.foreign" labels.
 *
 * @return list<string>
 */
function qaForeignKeys(string $table): array
{
    $labels = [];

    foreach (Schema::connection('master')->getForeignKeys($table) as $key) {
        $labels[] = implode(',', $key['columns']).'->'.$key['foreign_table'].'.'.implode(',', $key['foreign_columns']);
    }

    sort($labels);

    return $labels;
}

it('gives users the AC-2 columns and none of the stock ones', function () {
    $columns = qaColumns('users');

    expect($columns)->toContain('id', 'password_hash', 'is_platform_staff', 'status', 'failed_attempts', 'locked_until', 'email')
        ->and($columns)->not->toContain('password')
        ->and($columns)->not->toContain('remember_token')
        // The control that the reading can see a stock column at all: the
        // framework's own password_reset_tokens table is still there with its
        // `token`, so "no remember_token" is not a fact about an empty read.
        ->and(qaColumns('password_reset_tokens'))->toContain('token');
});

it('gives tenant_databases every column AC-3 lists, and a primary key on the tenant', function () {
    $expected = [
        'tenant_id', 'server_id', 'read_server_id', 'database_identifier', 'database_name', 'app_username',
        'app_credential_ref', 'migrate_username', 'migrate_credential_ref', 'credential_version',
        'credential_rotated_at', 'charset', 'collation', 'status', 'status_reason', 'schema_version',
        'target_schema_version', 'last_migrated_at', 'last_migration_error', 'profile', 'size_bytes',
        'table_count', 'row_estimate', 'measured_at', 'provisioned_at', 'last_opened_at', 'archived_at',
        'created_at', 'updated_at',
    ];

    sort($expected);

    expect(qaColumns('tenant_databases'))->toBe($expected)
        ->and(qaIndexes('tenant_databases'))->toContain('primary:tenant_id');
});

it('declares the AC-5 keys and indexes on tenant_databases', function () {
    expect(qaIndexes('tenant_databases'))->toContain(
        'primary:tenant_id',
        'unique:database_identifier',
        'unique:server_id,database_name',
        'index:schema_version',
        'index:status',
        'index:server_id,status'
    );
});

it('refuses a second mapping for one tenant and accepts one database name on two servers', function () {
    $connection = DB::connection('master');
    $row = static fn (string $tenant, string $server, string $identifier, string $name): array => [
        'tenant_id' => str_pad($tenant, 26, 'T'), 'server_id' => str_pad($server, 26, 'S'),
        'database_identifier' => $identifier, 'database_name' => $name, 'app_username' => 'u',
        'app_credential_ref' => 'env://A', 'migrate_username' => 'm', 'migrate_credential_ref' => 'env://M',
        'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'status' => 'active', 'profile' => 'standard',
        'created_at' => '2026-10-15 00:00:00.000', 'updated_at' => '2026-10-15 00:00:00.000',
    ];

    $connection->table('tenant_databases')->insert($row('one', 'a', 'id-one', 'shared_name'));

    // The control: the same database name on a different server is accepted
    // (the unique key is the pair, not the name).
    $connection->table('tenant_databases')->insert($row('two', 'b', 'id-two', 'shared_name'));

    expect($connection->table('tenant_databases')->count())->toBe(2);

    // Two mappings for one tenant: primary key.
    expect(fn () => $connection->table('tenant_databases')->insert($row('one', 'c', 'id-three', 'other')))
        ->toThrow(QueryException::class);

    // The same name twice on one server: the pair key.
    expect(fn () => $connection->table('tenant_databases')->insert($row('four', 'a', 'id-four', 'shared_name')))
        ->toThrow(QueryException::class);

    expect($connection->table('tenant_databases')->count())->toBe(2);
});

it('gives database_servers the AC-4 columns and tenant_databases a foreign key to it', function () {
    expect(qaColumns('database_servers'))->toContain(
        'host', 'port', 'engine', 'tls_mode', 'provisioner_credential_ref', 'max_tenants', 'tenant_count', 'status'
    )
        ->and(qaForeignKeys('tenant_databases'))->toContain('server_id->database_servers.id');
});

it('declares the foreign keys AC-7 relies on (declared only, the refusal is for the engine)', function () {
    expect(qaForeignKeys('tenant_databases'))->toContain('tenant_id->tenants.id', 'server_id->database_servers.id');
});

it('declares the AC-8 and AC-9 unique keys', function () {
    expect(qaIndexes('users'))->toContain('unique:email')
        ->and(qaIndexes('tenants'))->toContain('unique:code', 'unique:slug', 'unique:sequence')
        ->and(qaIndexes('tenant_users'))->toContain('unique:tenant_id,user_id', 'unique:tenant_id,employee_id')
        ->and(qaIndexes('plans'))->toContain('unique:code')
        ->and(qaIndexes('licences'))->toContain('unique:licence_key')
        ->and(qaIndexes('plan_limits'))->toContain('primary:plan_id,limit_key')
        ->and(qaIndexes('plan_features'))->toContain('primary:plan_id,feature_key')
        ->and(qaIndexes('tenant_feature_flags'))->toContain('primary:tenant_id,flag_key')
        ->and(qaForeignKeys('plan_features'))->toContain('feature_key->feature_flags.key')
        ->and(qaForeignKeys('tenant_feature_flags'))->toContain('flag_key->feature_flags.key');
});

it('declares the AC-10 audit indexes and an auto-incrementing key', function () {
    $id = collect(Schema::connection('master')->getColumns('platform_audit_logs'))->firstWhere('name', 'id');

    expect(qaIndexes('platform_audit_logs'))->toContain('index:tenant_id,created_at', 'index:action,created_at')
        ->and(qaColumns('platform_audit_logs'))->toContain('before', 'after')
        ->and($id['auto_increment'])->toBeTrue();
});

it('reads a different index set from a table that lacks them (the control)', function () {
    // qaIndexes() must be able to tell tables apart: a table without the AC-8
    // keys reports none of them.
    expect(qaIndexes('feature_flags'))->not->toContain('unique:email')
        ->and(qaIndexes('feature_flags'))->not->toContain('unique:code');
});
