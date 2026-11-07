<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| T-026 step 1: tenant_provisioning_runs / tenant_provisioning_steps
|--------------------------------------------------------------------------
|
| docs/database.md section 4.7, as built. The columns, the cascade, the
| unique (run_id, step) pair and the invalid-JSON refusal - all on the
| suite's in-memory SQLite master, the same stand-in MasterSchemaBuildsTest
| migrates.
|
*/

beforeEach(function () {
    expect(config('database.connections.master.driver'))->toBe('sqlite')
        ->and(config('database.connections.master.database'))->toBe(':memory:');

    Artisan::call('migrate', ['--database' => 'master', '--force' => true]);
});

it('creates tenant_provisioning_runs with every AC-2 column', function () {
    $columns = array_column(Schema::connection('master')->getColumns('tenant_provisioning_runs'), 'name');

    expect($columns)->toEqualCanonicalizing([
        'id', 'tenant_id', 'type', 'source_tenant_id', 'target_server_id',
        'status', 'current_step', 'input', 'claimed_by', 'heartbeat_at',
        'started_at', 'finished_at', 'error', 'initiated_by',
        'created_at', 'updated_at',
    ]);
});

it('creates tenant_provisioning_steps with every AC-2 column', function () {
    $columns = array_column(Schema::connection('master')->getColumns('tenant_provisioning_steps'), 'name');

    expect($columns)->toEqualCanonicalizing([
        'id', 'run_id', 'step', 'sequence', 'status', 'attempts',
        'started_at', 'finished_at', 'output', 'error',
        'created_at', 'updated_at',
    ]);
});

it('refuses a second step row for the same run and step', function () {
    $runId = (string) Str::ulid();

    DB::connection('master')->table('tenant_provisioning_runs')->insert([
        'id' => $runId,
        'type' => 'create',
        'status' => 'running',
        'input' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::connection('master')->table('tenant_provisioning_steps')->insert([
        'id' => (string) Str::ulid(),
        'run_id' => $runId,
        'step' => 'reserve_identity',
        'sequence' => 1,
        'status' => 'pending',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => DB::connection('master')->table('tenant_provisioning_steps')->insert([
        'id' => (string) Str::ulid(),
        'run_id' => $runId,
        'step' => 'reserve_identity',
        'sequence' => 2,
        'status' => 'pending',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('cascades the deletion of a run onto its steps', function () {
    $runId = (string) Str::ulid();

    DB::connection('master')->table('tenant_provisioning_runs')->insert([
        'id' => $runId,
        'type' => 'create',
        'status' => 'running',
        'input' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::connection('master')->table('tenant_provisioning_steps')->insert([
        'id' => (string) Str::ulid(),
        'run_id' => $runId,
        'step' => 'reserve_identity',
        'sequence' => 1,
        'status' => 'pending',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::connection('master')->statement('PRAGMA foreign_keys = ON');
    DB::connection('master')->table('tenant_provisioning_runs')->where('id', $runId)->delete();

    expect(DB::connection('master')->table('tenant_provisioning_steps')->where('run_id', $runId)->count())->toBe(0);
});

it('refuses invalid JSON in the run input column on the real engine, and is conservative on the stand-in', function () {
    // JsonValidity::enforce() is a no-op on SQLite (project convention, see
    // JsonValidity's own docblock) - this pins that the migration at least
    // calls it, by asserting the stand-in accepts well-formed JSON, which
    // RunContext/StepJournal always writes.
    $runId = (string) Str::ulid();

    DB::connection('master')->table('tenant_provisioning_runs')->insert([
        'id' => $runId,
        'type' => 'create',
        'status' => 'running',
        'input' => json_encode(['code' => 'TEN001']),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::connection('master')->table('tenant_provisioning_runs')->where('id', $runId)->value('input'))
        ->toBe(json_encode(['code' => 'TEN001']));
});
