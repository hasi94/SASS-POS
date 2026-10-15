<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| QA, T-008: the tenant baseline's declared shape, read from a stand-in
|--------------------------------------------------------------------------
|
| **Not evidence for AC-27 or AC-29 and not to be recorded as such.** Both are
| [E]: millisecond precision, decoded-JSON equality and refusal of invalid JSON
| are the engine's to answer, and while ENGINE_HOLD is set they are NOT
| VERIFIED (G-1). This is the half that needs no engine and that no shipped
| case asserts: the exact column list of `outbox_events` (and so the absence of
| a claim mark or a projection column, D-1), the indexes AC-27 and AC-29 name,
| and the defaults a new outbox row takes.
|
| The stand-in is a private in-memory SQLite connection; every case asserts it
| is SQLite before anything migrates, as TenantBaselineBuildsTest does.
*/

beforeEach(function () {
    config()->set('database.connections.qa_baseline_stand_in', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);

    expect(config('database.connections.qa_baseline_stand_in.driver'))->toBe('sqlite');

    expect(Artisan::call('migrate', [
        '--database' => 'qa_baseline_stand_in',
        '--path' => (string) config('schema.tenant_migration_path'),
        '--force' => true,
    ]))->toBe(0);
});

/**
 * @return list<string>
 */
function qaBaselineColumns(string $table): array
{
    $names = array_map(
        static fn (array $column): string => (string) $column['name'],
        Schema::connection('qa_baseline_stand_in')->getColumns($table)
    );

    sort($names);

    return $names;
}

/**
 * @return list<string>
 */
function qaBaselineIndexes(string $table): array
{
    $labels = [];

    foreach (Schema::connection('qa_baseline_stand_in')->getIndexes($table) as $index) {
        $labels[] = ($index['primary'] ? 'primary' : ($index['unique'] ? 'unique' : 'index')).':'.implode(',', $index['columns']);
    }

    sort($labels);

    return $labels;
}

it('gives outbox_events exactly the AC-27 columns and no claim mark', function () {
    $expected = [
        'id', 'type', 'version', 'branch_id', 'aggregate_type', 'aggregate_id', 'payload', 'occurred_at',
        'correlation_id', 'causation_id', 'actor', 'status', 'published_at', 'attempts', 'last_error',
    ];

    sort($expected);

    expect(qaBaselineColumns('outbox_events'))->toBe($expected)
        // The control for the exactness: a different table does not read as
        // this list.
        ->and(qaBaselineColumns('schema_meta'))->not->toBe($expected);
});

it('indexes outbox_events on status with the time, and on the aggregate', function () {
    expect(qaBaselineIndexes('outbox_events'))->toContain('index:status,occurred_at', 'index:aggregate_type,aggregate_id');
});

it('gives a new outbox row status pending and attempts zero', function () {
    $connection = DB::connection('qa_baseline_stand_in');

    $connection->table('outbox_events')->insert([
        'id' => str_pad('EVT', 26, '0'),
        'type' => 'qa.checked',
        'version' => 1,
        'aggregate_type' => 'qa',
        'aggregate_id' => str_pad('AGG', 26, '0'),
        'payload' => '{"a":1}',
        'occurred_at' => '2026-01-02 03:04:05.678',
    ]);

    $row = (array) $connection->table('outbox_events')->first();

    expect($row['status'])->toBe('pending')
        ->and((int) $row['attempts'])->toBe(0)
        ->and($row['published_at'])->toBeNull();
});

it('declares the AC-29 audit_logs indexes and an auto-incrementing key', function () {
    $id = collect(Schema::connection('qa_baseline_stand_in')->getColumns('audit_logs'))->firstWhere('name', 'id');

    expect(qaBaselineIndexes('audit_logs'))->toContain(
        'index:created_at',
        'index:subject_type,subject_id',
        'index:actor_id,created_at',
        'index:module,action,created_at'
    )
        ->and($id['auto_increment'])->toBeTrue()
        ->and(qaBaselineColumns('audit_logs'))->toContain('before', 'after', 'changed_fields');
});
