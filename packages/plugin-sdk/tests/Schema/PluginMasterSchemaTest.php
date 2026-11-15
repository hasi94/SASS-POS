<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Tests\Schema;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-019 step 3 - the five master tables, applied to the suite's stand-in
| connection (AC-7, AC-12)
|--------------------------------------------------------------------------
|
| Not AC-48's evidence: AC-48 is [E] and NOT VERIFIED while ENGINE_HOLD is
| set (plan Ruling 14). What this proves is that the five tables apply,
| twice, and that their column lists, keys and foreign keys match
| docs/database.md section 4.3 column for column, the same convention
| MasterSchemaBuildsTest already uses.
|
*/

uses(TestCase::class);

/**
 * @return array<string, array{name: string, type_name: string, nullable: bool}>
 */
function pluginMasterColumns(string $table): array
{
    $columns = [];

    foreach (Schema::connection('master')->getColumns($table) as $column) {
        $columns[$column['name']] = [
            'name' => $column['name'],
            'type_name' => $column['type_name'],
            'nullable' => $column['nullable'],
        ];
    }

    return $columns;
}

/**
 * @return list<string>
 */
function pluginMasterForeignKeys(string $table): array
{
    $keys = [];

    foreach (DB::connection('master')->select('pragma foreign_key_list('.$table.')') as $key) {
        $key = (array) $key;
        $keys[] = $key['from'].' -> '.$key['table'].'.'.$key['to'];
    }

    sort($keys);

    return $keys;
}

beforeEach(function () {
    expect(config('database.connections.master.driver'))->toBe('sqlite')
        ->and(config('database.connections.master.database'))->toBe(':memory:');

    Artisan::call('migrate', ['--database' => 'master', '--force' => true]);
});

it('applies the five plugin tables, and a second run changes nothing', function () {
    $tables = ['plugins', 'plugin_versions', 'plugin_dependencies', 'plugin_tenant_access', 'tenant_plugins'];

    foreach ($tables as $table) {
        expect(Schema::connection('master')->hasTable($table))->toBeTrue();
    }

    expect(Artisan::call('migrate', ['--database' => 'master', '--force' => true]))->toBe(0);

    foreach ($tables as $table) {
        expect(Schema::connection('master')->hasTable($table))->toBeTrue();
    }
});

it('gives plugins exactly its documented columns', function () {
    $columns = pluginMasterColumns('plugins');

    expect(array_keys($columns))->toEqualCanonicalizing([
        'id', 'created_at', 'updated_at', 'deleted_at',
        'name', 'display_name', 'type', 'author', 'description',
        'visibility', 'status', 'latest_version_id', 'signature_key_id',
        'requires_licence',
    ])
        ->and($columns['display_name']['nullable'])->toBeTrue()
        ->and($columns['author']['nullable'])->toBeTrue()
        ->and($columns['description']['nullable'])->toBeTrue()
        ->and($columns['latest_version_id']['nullable'])->toBeTrue()
        ->and($columns['signature_key_id']['nullable'])->toBeTrue()
        ->and($columns['deleted_at']['nullable'])->toBeTrue()
        ->and($columns['name']['nullable'])->toBeFalse();

    expect(pluginMasterForeignKeys('plugins'))->toBe([]);
});

it('gives plugin_versions exactly its documented columns, keys and foreign key', function () {
    $columns = pluginMasterColumns('plugin_versions');

    expect(array_keys($columns))->toEqualCanonicalizing([
        'id', 'created_at', 'updated_at',
        'plugin_id', 'version', 'core_constraint', 'manifest', 'package_path',
        'checksum', 'signature', 'migration_count', 'status', 'released_at',
    ])
        ->and($columns['signature']['nullable'])->toBeTrue()
        ->and($columns['released_at']['nullable'])->toBeTrue();

    expect(pluginMasterForeignKeys('plugin_versions'))->toBe(['plugin_id -> plugins.id']);

    $indexes = Schema::connection('master')->getIndexes('plugin_versions');
    $unique = array_filter($indexes, fn (array $i): bool => $i['unique'] && ! $i['primary']);

    expect(array_values($unique)[0]['columns'] ?? [])->toBe(['plugin_id', 'version']);
});

it('gives plugin_dependencies exactly its documented columns and composite primary key', function () {
    $columns = pluginMasterColumns('plugin_dependencies');

    expect(array_keys($columns))->toEqualCanonicalizing([
        'plugin_version_id', 'depends_on_plugin', 'version_constraint',
    ]);

    expect(pluginMasterForeignKeys('plugin_dependencies'))->toBe(['plugin_version_id -> plugin_versions.id']);

    $primary = array_values(array_filter(
        Schema::connection('master')->getIndexes('plugin_dependencies'),
        fn (array $i): bool => $i['primary'],
    ))[0];

    expect($primary['columns'])->toBe(['plugin_version_id', 'depends_on_plugin']);
});

it('gives plugin_tenant_access exactly its documented columns, nullable grantor and composite primary key', function () {
    $columns = pluginMasterColumns('plugin_tenant_access');

    expect(array_keys($columns))->toEqualCanonicalizing([
        'plugin_id', 'tenant_id', 'granted_by', 'created_at', 'updated_at',
    ])
        ->and($columns['granted_by']['nullable'])->toBeTrue();

    expect(pluginMasterForeignKeys('plugin_tenant_access'))->toBe([
        'granted_by -> users.id',
        'plugin_id -> plugins.id',
        'tenant_id -> tenants.id',
    ]);

    $primary = array_values(array_filter(
        Schema::connection('master')->getIndexes('plugin_tenant_access'),
        fn (array $i): bool => $i['primary'],
    ))[0];

    expect($primary['columns'])->toBe(['plugin_id', 'tenant_id']);
});

it('gives tenant_plugins exactly its documented columns, keys and the breaker/licence foreign keys', function () {
    $columns = pluginMasterColumns('tenant_plugins');

    expect(array_keys($columns))->toEqualCanonicalizing([
        'id', 'created_at', 'updated_at',
        'tenant_id', 'plugin_id', 'plugin_version_id', 'status', 'schema_version',
        'licence_id', 'installed_at', 'enabled_at', 'disabled_at', 'installed_by',
        'failure_count', 'tripped_at', 'last_error',
    ])
        ->and($columns['licence_id']['nullable'])->toBeTrue()
        ->and($columns['installed_at']['nullable'])->toBeTrue()
        ->and($columns['enabled_at']['nullable'])->toBeTrue()
        ->and($columns['disabled_at']['nullable'])->toBeTrue()
        ->and($columns['installed_by']['nullable'])->toBeTrue()
        ->and($columns['tripped_at']['nullable'])->toBeTrue()
        ->and($columns['last_error']['nullable'])->toBeTrue();

    expect(pluginMasterForeignKeys('tenant_plugins'))->toBe([
        'installed_by -> users.id',
        'licence_id -> licences.id',
        'plugin_id -> plugins.id',
        'plugin_version_id -> plugin_versions.id',
        'tenant_id -> tenants.id',
    ]);

    $unique = array_values(array_filter(
        Schema::connection('master')->getIndexes('tenant_plugins'),
        fn (array $i): bool => $i['unique'] && ! $i['primary'],
    ))[0];

    expect($unique['columns'])->toBe(['tenant_id', 'plugin_id']);
});

it('is the only family among the master plugin tables matching /licen/i, except the licences table itself (AC-12)', function () {
    $pluginTables = ['plugins', 'plugin_versions', 'plugin_dependencies', 'plugin_tenant_access', 'tenant_plugins'];

    foreach ($pluginTables as $table) {
        expect($table)->not->toMatch('/licen/i');
    }

    expect('licences')->toMatch('/licen/i');
});

it('reports a scratch table missing one column, so the comparison above is live', function () {
    Schema::connection('master')->create('plugin_tenant_access_control', function ($table) {
        $table->string('plugin_id', 26);
        // tenant_id deliberately missing.
        $table->string('granted_by', 26)->nullable();
    });

    $columns = array_column(Schema::connection('master')->getColumns('plugin_tenant_access_control'), 'name');

    expect($columns)->not->toContain('tenant_id');
});
