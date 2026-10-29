<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Tests\Schema;

use Flexipos\Kernel\Schema\Support\MigrationTree;
use Flexipos\Kernel\Schema\Support\SourceRules;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| T-019 step 4 - the three tenant-side plugin data tables (AC-18 columns,
| D-4's five-value mode enum)
|--------------------------------------------------------------------------
*/

uses(TestCase::class);

/**
 * The stand-in connection this file migrates the tenant tree onto, kept
 * distinct from the shared `tenant` and `tenant_baseline_stand_in` names
 * other suites use.
 */
function pluginTenantStandIn(): string
{
    return 'plugin_tenant_schema_stand_in';
}

/**
 * @return array<string, array{name: string, nullable: bool}>
 */
function pluginTenantColumns(string $table): array
{
    $columns = [];

    foreach (Schema::connection(pluginTenantStandIn())->getColumns($table) as $column) {
        $columns[$column['name']] = ['name' => $column['name'], 'nullable' => $column['nullable']];
    }

    return $columns;
}

beforeEach(function () {
    config()->set('database.connections.'.pluginTenantStandIn(), [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);

    expect(config('database.connections.'.pluginTenantStandIn().'.driver'))->toBe('sqlite');

    expect(Artisan::call('migrate', [
        '--database' => pluginTenantStandIn(),
        '--path' => tenantMigrationPath(),
        '--force' => true,
    ]))->toBe(0);
});

it('applies the three tables', function () {
    foreach (['plugin_settings', 'plugin_migrations', 'plugin_execution_logs'] as $table) {
        expect(Schema::connection(pluginTenantStandIn())->hasTable($table))->toBeTrue();
    }
});

it('gives plugin_settings exactly its documented columns and composite primary key', function () {
    $columns = pluginTenantColumns('plugin_settings');

    expect(array_keys($columns))->toEqualCanonicalizing([
        'plugin_name', 'key', 'value', 'is_secret', 'updated_by', 'created_at', 'updated_at',
    ])
        ->and($columns['value']['nullable'])->toBeTrue()
        ->and($columns['updated_by']['nullable'])->toBeTrue();

    $primary = array_values(array_filter(
        Schema::connection(pluginTenantStandIn())->getIndexes('plugin_settings'),
        fn (array $i): bool => $i['primary'],
    ))[0];

    expect($primary['columns'])->toBe(['plugin_name', 'key']);
});

it('gives plugin_migrations exactly its documented columns and composite primary key', function () {
    $columns = pluginTenantColumns('plugin_migrations');

    expect(array_keys($columns))->toEqualCanonicalizing(['plugin_name', 'migration', 'batch', 'ran_at']);

    $primary = array_values(array_filter(
        Schema::connection(pluginTenantStandIn())->getIndexes('plugin_migrations'),
        fn (array $i): bool => $i['primary'],
    ))[0];

    expect($primary['columns'])->toBe(['plugin_name', 'migration']);
});

it('gives plugin_execution_logs exactly its documented columns', function () {
    $columns = pluginTenantColumns('plugin_execution_logs');

    expect(array_keys($columns))->toEqualCanonicalizing([
        'id', 'plugin_name', 'point', 'mode', 'duration_ms', 'status', 'error', 'request_id', 'created_at',
    ])
        ->and($columns['error']['nullable'])->toBeTrue()
        ->and($columns['request_id']['nullable'])->toBeTrue();
});

it('reports nothing from SourceRules for the three new tenant files (AC-19)', function () {
    $files = [
        'database/migrations-tenant/2026_10_29_000801_create_plugin_settings_table.php',
        'database/migrations-tenant/2026_10_29_000802_create_plugin_migrations_table.php',
        'database/migrations-tenant/2026_10_29_000803_create_plugin_execution_logs_table.php',
    ];

    foreach ($files as $file) {
        $path = base_path($file);

        expect(SourceRules::inspect(file_get_contents($path), MigrationTree::Tenant))->toBe([]);
    }
});

it('accepts and reads back a mode=add row - D-4s five-value enum', function () {
    DB::connection(pluginTenantStandIn())->table('plugin_execution_logs')->insert([
        'plugin_name' => 'fixture-echo',
        'point' => 'fixture.echo',
        'mode' => 'add',
        'duration_ms' => 1,
        'status' => 'ok',
        'created_at' => now(),
    ]);

    $row = DB::connection(pluginTenantStandIn())->table('plugin_execution_logs')->first();

    expect($row->mode)->toBe('add');
});
