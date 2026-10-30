<?php

use Flexipos\Testing\Engine\EngineConnection;
use Flexipos\Testing\Engine\EngineDatabase;
use Flexipos\Testing\Engine\EngineOnHold;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| T-020 step 13 - the five workflow tables, against a real MySQL/MariaDB
| connection (AC-4, still NOT VERIFIED while ENGINE_HOLD is set; the code
| to verify it is ready)
|--------------------------------------------------------------------------
|
| The database here is created and dropped by name, through EngineDatabase,
| in the same run - never a sweep. Nothing in this file is evidence for
| AC-4 while the hold is set; it is recorded NOT VERIFIED and the gate G-1
| named, same as every other engine test in this repository.
*/

function workflowSchemaEngineTenantName(): string
{
    return 'flexipos_t8test_t020_tenant';
}

it('refuses to reach the engine at all while the hold is set', function () {
    expect(fn () => EngineConnection::connection(workflowSchemaEngineTenantName()))->toThrow(EngineOnHold::class);
})->skip(fn (): bool => ! EngineConnection::onHold(), 'the ENGINE_HOLD suspension has been cleared');

it('applies the tenant tree, holds the five workflow tables and their keys, refuses invalid JSON, and migrating twice is a no-op', function () {
    $db = EngineDatabase::engine();
    $tenantName = workflowSchemaEngineTenantName();

    $db->recreate($tenantName);

    try {
        $tenantConnectionName = EngineConnection::connectionName($tenantName);
        EngineConnection::connection($tenantName);

        expect(Artisan::call('migrate', [
            '--database' => $tenantConnectionName,
            '--path' => (string) config('schema.tenant_migration_path'),
            '--force' => true,
        ]))->toBe(0);

        foreach (['workflow_definitions', 'workflow_steps', 'workflow_instances', 'workflow_tasks', 'workflow_events'] as $table) {
            expect(Schema::connection($tenantConnectionName)->hasTable($table))->toBeTrue();
        }

        $amountColumn = DB::connection($tenantConnectionName)->selectOne(
            "select column_type from information_schema.columns
             where table_schema = ? and table_name = 'workflow_instances' and column_name = 'amount'",
            [$tenantName]
        );

        expect(strtolower((string) $amountColumn->column_type))->toBe('decimal(18,4)');

        expect(fn () => DB::connection($tenantConnectionName)->table('workflow_definitions')->insert([
            'id' => str_pad('badjson', 26, 'x'),
            'created_at' => now(),
            'updated_at' => now(),
            'key' => 'bad-json-key',
            'name' => 'n',
            'entity_type' => 'custom',
            'conditions' => 'not valid json',
            'priority' => 1,
            'on_reject' => 'stop',
            'version' => 1,
        ]))->toThrow(QueryException::class);

        $before = Schema::connection($tenantConnectionName)->getTableListing();

        expect(Artisan::call('migrate', [
            '--database' => $tenantConnectionName,
            '--path' => (string) config('schema.tenant_migration_path'),
            '--force' => true,
        ]))->toBe(0);

        expect(Schema::connection($tenantConnectionName)->getTableListing())->toBe($before);
    } finally {
        $db->drop($tenantName);
    }
})->skip(fn (): bool => EngineConnection::onHold(), 'ENGINE_HOLD: see Flexipos\Testing\Engine\EngineOnHold');
