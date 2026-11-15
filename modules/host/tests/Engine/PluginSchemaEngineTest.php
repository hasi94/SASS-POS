<?php

use Flexipos\Kernel\Schema\Support\SourceRules;
use Flexipos\PluginSdk\Manifest\PluginName;
use Flexipos\PluginSdk\Migrations\PluginMigrationRunner;
use Flexipos\Testing\Engine\EngineConnection;
use Flexipos\Testing\Engine\EngineDatabase;
use Flexipos\Testing\Engine\EngineOnHold;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| T-019 step 14 - the five master tables and the plugin migration runner,
| against real databases (AC-48, still NOT VERIFIED while ENGINE_HOLD is
| set; the code to verify it is ready)
|--------------------------------------------------------------------------
|
| Every database here is created and dropped by name, through
| EngineDatabase, in the same run - never a sweep. Nothing in this file is
| evidence for AC-48 while the hold is set; it is recorded NOT VERIFIED
| and the gate G-1 named, same as every other engine test in this
| repository.
*/

function pluginSchemaEngineMasterName(): string
{
    return 'flexipos_t8test_t019_master';
}

function pluginSchemaEngineTenantName(): string
{
    return 'flexipos_t8test_t019_tenant';
}

it('refuses to reach the engine at all while the hold is set', function () {
    expect(fn () => EngineConnection::connection(pluginSchemaEngineMasterName()))->toThrow(EngineOnHold::class);
})->skip(fn (): bool => ! EngineConnection::onHold(), 'the ENGINE_HOLD suspension has been cleared');

it('applies the five master tables, migrates a plugin against the tenant tree, then drops its tables', function () {
    $db = EngineDatabase::engine();
    $masterName = pluginSchemaEngineMasterName();
    $tenantName = pluginSchemaEngineTenantName();

    $db->recreate($masterName);
    $db->recreate($tenantName);

    try {
        $masterConnectionName = EngineConnection::connectionName($masterName);
        EngineConnection::connection($masterName);

        expect(Artisan::call('migrate', ['--database' => $masterConnectionName, '--force' => true]))->toBe(0);

        foreach (['plugins', 'plugin_versions', 'plugin_dependencies', 'plugin_tenant_access', 'tenant_plugins'] as $table) {
            expect(Schema::connection($masterConnectionName)->hasTable($table))->toBeTrue();
        }

        $tenantConnectionName = EngineConnection::connectionName($tenantName);
        EngineConnection::connection($tenantName);

        expect(Artisan::call('migrate', [
            '--database' => $tenantConnectionName,
            '--path' => (string) config('schema.tenant_migration_path'),
            '--force' => true,
        ]))->toBe(0);

        $previousConnection = config('tenancy.connection');
        config(['tenancy.connection' => $tenantConnectionName]);

        try {
            $migrationsDir = __DIR__.'/../../../../packages/plugin-sdk/tests/Fixtures/FixtureEcho/migrations';
            $applied = app(PluginMigrationRunner::class)->run('fixture-echo', $migrationsDir);

            expect($applied)->toBe(2)
                ->and(Schema::connection($tenantConnectionName)->hasTable('plg_fixture_echo_after_effects'))->toBeTrue()
                ->and(Schema::connection($tenantConnectionName)->hasTable('plg_fixture_echo_notes'))->toBeTrue();

            $foreignKeys = DB::connection($tenantConnectionName)->select(
                "select referenced_table_name from information_schema.key_column_usage
                 where table_schema = ? and table_name = 'plg_fixture_echo_notes'
                 and column_name = 'employee_id' and referenced_table_name is not null",
                [$tenantName]
            );

            expect($foreignKeys)->not->toBe([])
                ->and((string) $foreignKeys[0]->referenced_table_name)->toBe('employees');

            // Uninstall's drop: the owned tables, read from the plugin's own
            // migration files (never by prefix match), asserted against the
            // prefix before each drop - the same guard UninstallPluginHandler
            // applies.
            $prefix = PluginName::tablePrefix('fixture-echo');
            $owned = [];

            foreach (glob($migrationsDir.'/*.php') ?: [] as $file) {
                foreach (SourceRules::tablesCreatedIn((string) file_get_contents($file)) as $table) {
                    $owned[] = $table;
                }
            }

            foreach ($owned as $table) {
                expect(str_starts_with($table, $prefix))->toBeTrue();

                Schema::connection($tenantConnectionName)->drop($table);
            }

            foreach ($owned as $table) {
                expect(Schema::connection($tenantConnectionName)->hasTable($table))->toBeFalse();
            }
        } finally {
            config(['tenancy.connection' => $previousConnection]);
        }
    } finally {
        $db->drop($masterName);
        $db->drop($tenantName);
    }
})->skip(fn (): bool => EngineConnection::onHold(), 'ENGINE_HOLD: see Flexipos\Testing\Engine\EngineOnHold');
