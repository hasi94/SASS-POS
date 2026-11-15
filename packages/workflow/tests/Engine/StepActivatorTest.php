<?php

use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Workflow\Definitions\ApproverType;
use Flexipos\Workflow\Definitions\Quorum;
use Flexipos\Workflow\Definitions\ValidatedStep;
use Flexipos\Workflow\Engine\StepActivator;
use Flexipos\Workflow\Support\TenantConnection;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class);

require_once __DIR__.'/../Support/workflow-fixture.php';

beforeEach(function () {
    AccessTenantFixture::boot();
    workflowEnterTenant();
});

afterEach(function () {
    AccessTenantFixture::forget();
});

function wfInstanceRow(string $requestedBy): string
{
    $id = (string) Str::ulid();
    $now = now()->format('Y-m-d H:i:s.v');

    $definitionId = (string) Str::ulid();

    app(TenantConnection::class)->get()->table('workflow_definitions')->insert([
        'id' => $definitionId,
        'created_at' => $now,
        'updated_at' => $now,
        'key' => 'wf-step-activator-'.$id,
        'name' => 'x',
        'entity_type' => 'custom',
        'conditions' => '[]',
        'priority' => 1,
        'on_reject' => 'stop',
        'version' => 1,
    ]);

    app(TenantConnection::class)->get()->table('workflow_instances')->insert([
        'id' => $id,
        'created_at' => $now,
        'updated_at' => $now,
        'definition_id' => $definitionId,
        'definition_version' => 1,
        'entity_type' => 'custom',
        'entity_id' => (string) Str::ulid(),
        'requested_by' => $requestedBy,
        'current_step' => 1,
        'status' => 'pending',
        'started_at' => $now,
        'context' => '{}',
    ]);

    return $id;
}

it('writes one task and one assigned event per approver, with assignee_role_id set', function () {
    $requester = AccessSeed::employee('wf-step-requester');
    $role = AccessSeed::role('wf_step_role');
    $e1 = AccessSeed::employee('wf-step-1');
    $e2 = AccessSeed::employee('wf-step-2');
    AccessSeed::assign($e1, $role);
    AccessSeed::assign($e2, $role);

    $instanceId = wfInstanceRow($requester);
    $step = new ValidatedStep(1, 'Step', ApproverType::Role, ['roles' => [$role]], Quorum::Any);

    $assignments = app(StepActivator::class)->activate($instanceId, $step, null, $requester);

    expect($assignments)->toHaveCount(2);

    $tasks = app(TenantConnection::class)->get()->table('workflow_tasks')->where('instance_id', $instanceId)->get();

    expect($tasks)->toHaveCount(2);

    foreach ($tasks as $task) {
        expect($task->assignee_role_id)->toBe($role);
    }

    $events = app(TenantConnection::class)->get()->table('workflow_events')->where('instance_id', $instanceId)->where('event', 'assigned')->get();

    expect($events)->toHaveCount(2);
});

it('writes zero tasks for an empty approver set (AC-12)', function () {
    $requester = AccessSeed::employee('wf-step-requester-2');
    $role = AccessSeed::role('wf_step_empty_role');

    $instanceId = wfInstanceRow($requester);
    $step = new ValidatedStep(1, 'Step', ApproverType::Role, ['roles' => [$role]], Quorum::Any);

    $assignments = app(StepActivator::class)->activate($instanceId, $step, null, $requester);

    expect($assignments)->toBe([]);

    $tasks = app(TenantConnection::class)->get()->table('workflow_tasks')->where('instance_id', $instanceId)->count();

    expect($tasks)->toBe(0);
});
