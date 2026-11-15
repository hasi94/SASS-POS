<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| The five workflow tables, applied to a SQLite stand-in (T-020 step 3,
| the T-019 shape)
|--------------------------------------------------------------------------
|
| AC-1 (column-for-column), AC-2 (the unique key backstop), AC-3 (the step
| foreign key and unique index), AC-4's stand-in half, AC-22 (every
| escalation/SLA column is present and writable).
*/

function wfSchemaConnection(): string
{
    return 'workflow_schema_stand_in';
}

function wfApplyTenantTree(): void
{
    config()->set('database.connections.'.wfSchemaConnection(), [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);

    DB::purge(wfSchemaConnection());

    expect(Artisan::call('migrate', [
        '--database' => wfSchemaConnection(),
        '--path' => (string) config('schema.tenant_migration_path'),
        '--force' => true,
    ]))->toBe(0);
}

/**
 * @return list<string>
 */
function wfColumns(string $table): array
{
    $names = array_column(Schema::connection(wfSchemaConnection())->getColumns($table), 'name');
    sort($names);

    return $names;
}

it('creates workflow_definitions with exactly the documented columns', function () {
    wfApplyTenantTree();

    expect(wfColumns('workflow_definitions'))->toBe([
        'branch_ids', 'conditions', 'created_at', 'created_by', 'deleted_at', 'entity_type',
        'escalation_hours', 'id', 'is_active', 'key', 'name', 'on_reject', 'plugin_name',
        'priority', 'updated_at', 'updated_by', 'version',
    ]);
});

it('creates workflow_steps with exactly the documented columns, no timestamps', function () {
    wfApplyTenantTree();

    expect(wfColumns('workflow_steps'))->toBe([
        'approver_ref', 'approver_type', 'can_edit', 'can_skip_if', 'definition_id', 'escalate_to',
        'id', 'name', 'quorum', 'sequence', 'sla_hours',
    ]);
});

it('creates workflow_instances with exactly the documented columns', function () {
    wfApplyTenantTree();

    expect(wfColumns('workflow_instances'))->toBe([
        'amount', 'branch_id', 'completed_at', 'context', 'created_at', 'current_step',
        'definition_id', 'definition_version', 'entity_id', 'entity_type', 'id', 'requested_by',
        'sla_due_at', 'started_at', 'status', 'updated_at',
    ]);
});

it('creates workflow_tasks with exactly the documented columns', function () {
    wfApplyTenantTree();

    expect(wfColumns('workflow_tasks'))->toBe([
        'assignee_employee_id', 'assignee_role_id', 'comment', 'created_at', 'decided_at',
        'decided_by', 'delegated_to', 'id', 'instance_id', 'notified_at', 'reminded_at',
        'status', 'step_sequence', 'updated_at',
    ]);
});

it('creates workflow_events with exactly the documented columns', function () {
    wfApplyTenantTree();

    expect(wfColumns('workflow_events'))->toBe([
        'actor_employee_id', 'comment', 'created_at', 'data', 'event', 'id', 'instance_id', 'task_id',
    ]);
});

it('holds the unique keys workflow_definitions(key) and workflow_steps(definition_id, sequence)', function () {
    wfApplyTenantTree();

    $connection = DB::connection(wfSchemaConnection());
    $now = '2026-10-30 00:00:00.000';

    $definitionId = str_pad('def1', 26, 'x');
    $requester = str_pad('req1', 26, 'x');

    $connection->table('employees')->insert([
        'id' => $requester, 'code' => 'req', 'first_name' => 'r', 'display_name' => 'r', 'status' => 'active',
        'perm_version' => 1, 'created_at' => $now, 'updated_at' => $now,
    ]);

    $connection->table('workflow_definitions')->insert([
        'id' => $definitionId, 'created_at' => $now, 'updated_at' => $now, 'key' => 'dup-key',
        'name' => 'n', 'entity_type' => 'custom', 'conditions' => '[]', 'priority' => 1,
        'on_reject' => 'stop', 'version' => 1,
    ]);

    // AC-2 backstop.
    expect(fn () => $connection->table('workflow_definitions')->insert([
        'id' => str_pad('def2', 26, 'x'), 'created_at' => $now, 'updated_at' => $now, 'key' => 'dup-key',
        'name' => 'n2', 'entity_type' => 'custom', 'conditions' => '[]', 'priority' => 1,
        'on_reject' => 'stop', 'version' => 1,
    ]))->toThrow(QueryException::class);

    // AC-3: an unknown definition_id is refused (foreign keys on).
    expect(fn () => $connection->table('workflow_steps')->insert([
        'id' => str_pad('step1', 26, 'x'), 'definition_id' => str_pad('nope', 26, 'x'), 'sequence' => 1,
        'name' => 's', 'approver_type' => 'role', 'approver_ref' => '{}', 'quorum' => 'any',
    ]))->toThrow(QueryException::class);

    $connection->table('workflow_steps')->insert([
        'id' => str_pad('step2', 26, 'x'), 'definition_id' => $definitionId, 'sequence' => 1,
        'name' => 's', 'approver_type' => 'role', 'approver_ref' => '{}', 'quorum' => 'any',
    ]);

    // AC-3: a duplicate (definition_id, sequence) is refused.
    expect(fn () => $connection->table('workflow_steps')->insert([
        'id' => str_pad('step3', 26, 'x'), 'definition_id' => $definitionId, 'sequence' => 1,
        'name' => 's', 'approver_type' => 'role', 'approver_ref' => '{}', 'quorum' => 'any',
    ]))->toThrow(QueryException::class);

    // Control: a distinct sequence is accepted.
    $connection->table('workflow_steps')->insert([
        'id' => str_pad('step4', 26, 'x'), 'definition_id' => $definitionId, 'sequence' => 2,
        'name' => 's', 'approver_type' => 'role', 'approver_ref' => '{}', 'quorum' => 'all',
    ]);

    expect($connection->table('workflow_steps')->where('definition_id', $definitionId)->count())->toBe(2);
});

it('reads back every escalation/SLA column it writes (AC-22)', function () {
    wfApplyTenantTree();

    $connection = DB::connection(wfSchemaConnection());
    $now = '2026-10-30 00:00:00.000';

    $requester = str_pad('reqw', 26, 'x');
    $connection->table('employees')->insert([
        'id' => $requester, 'code' => 'reqw', 'first_name' => 'r', 'display_name' => 'r', 'status' => 'active',
        'perm_version' => 1, 'created_at' => $now, 'updated_at' => $now,
    ]);

    $definitionId = str_pad('defsla', 26, 'x');
    $connection->table('workflow_definitions')->insert([
        'id' => $definitionId, 'created_at' => $now, 'updated_at' => $now, 'key' => 'sla-key',
        'name' => 'n', 'entity_type' => 'custom', 'conditions' => '[]', 'priority' => 1,
        'on_reject' => 'stop', 'version' => 1, 'escalation_hours' => 24,
    ]);

    expect((int) $connection->table('workflow_definitions')->where('id', $definitionId)->value('escalation_hours'))->toBe(24);

    $connection->table('workflow_steps')->insert([
        'id' => str_pad('stepsla', 26, 'x'), 'definition_id' => $definitionId, 'sequence' => 1,
        'name' => 's', 'approver_type' => 'role', 'approver_ref' => '{}', 'quorum' => 'any',
        'sla_hours' => 12, 'escalate_to' => '{"roles":["x"]}', 'can_skip_if' => '[]',
    ]);

    $step = $connection->table('workflow_steps')->where('id', str_pad('stepsla', 26, 'x'))->first();

    expect((int) $step->sla_hours)->toBe(12)
        ->and($step->escalate_to)->toBe('{"roles":["x"]}')
        ->and($step->can_skip_if)->toBe('[]');

    $instanceId = str_pad('instsla', 26, 'x');
    $connection->table('workflow_instances')->insert([
        'id' => $instanceId, 'created_at' => $now, 'updated_at' => $now, 'definition_id' => $definitionId,
        'definition_version' => 1, 'entity_type' => 'custom', 'entity_id' => str_pad('entsla', 26, 'x'),
        'requested_by' => $requester, 'current_step' => 1, 'status' => 'pending', 'started_at' => $now,
        'context' => '{}', 'sla_due_at' => $now,
    ]);

    expect((string) $connection->table('workflow_instances')->where('id', $instanceId)->value('sla_due_at'))->toBe($now);

    $taskId = str_pad('tasksla', 26, 'x');
    $connection->table('workflow_tasks')->insert([
        'id' => $taskId, 'created_at' => $now, 'updated_at' => $now, 'instance_id' => $instanceId,
        'step_sequence' => 1, 'status' => 'pending', 'notified_at' => $now, 'reminded_at' => $now,
    ]);

    $task = $connection->table('workflow_tasks')->where('id', $taskId)->first();

    expect((string) $task->notified_at)->toBe($now)
        ->and((string) $task->reminded_at)->toBe($now);
});

it('runs cleanly twice as a no-op, and no file declares a down() (AC-4 stand-in half)', function () {
    wfApplyTenantTree();

    $before = Schema::connection(wfSchemaConnection())->getTableListing();

    expect(Artisan::call('migrate', [
        '--database' => wfSchemaConnection(),
        '--path' => (string) config('schema.tenant_migration_path'),
        '--force' => true,
    ]))->toBe(0);

    expect(Schema::connection(wfSchemaConnection())->getTableListing())->toBe($before);

    foreach (glob(base_path((string) config('schema.tenant_migration_path')).'/2026_10_30_*.php') as $file) {
        expect(file_get_contents($file))->not->toContain('function down(');
    }
});
