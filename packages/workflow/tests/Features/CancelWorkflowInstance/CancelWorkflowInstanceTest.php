<?php

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Workflow\WorkflowActionRefused;
use Flexipos\Contracts\Workflow\WorkflowContext;
use Flexipos\Contracts\Workflow\WorkflowEntityType;
use Flexipos\Contracts\Workflow\WorkflowGate;
use Flexipos\Contracts\Workflow\WorkflowInstanceStatus;
use Flexipos\Contracts\Workflow\WorkflowVerdict;
use Flexipos\Workflow\Features\RegisterWorkflowDefinition\RegisterWorkflowDefinition;
use Flexipos\Workflow\Features\RegisterWorkflowDefinition\RegisterWorkflowDefinitionHandler;
use Flexipos\Workflow\Support\TenantConnection;
use Flexipos\Workflow\Tests\Fixtures\FixtureApproval;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class);

require_once __DIR__.'/../../Support/workflow-fixture.php';

beforeEach(function () {
    AccessTenantFixture::boot();
    workflowEnterTenant();
});

afterEach(function () {
    AccessTenantFixture::forget();
});

it('cancels a pending instance, skips its pending tasks, and refuses a later decide or a second cancel (AC-21)', function () {
    $chain = workflowSeedChain();

    app(RegisterWorkflowDefinitionHandler::class)->handle(new RegisterWorkflowDefinition(
        FixtureApproval::json($chain['roleOne'], $chain['roleTwo']),
    ));

    $context = new WorkflowContext((string) Str::ulid(), $chain['requester'], null, '5000');
    $instanceId = app(WorkflowGate::class)->evaluate(WorkflowEntityType::Custom, $context)->instanceId;

    $state = app(WorkflowGate::class)->cancel($instanceId, $chain['requester'], 'withdrawn');

    expect($state->status)->toBe(WorkflowInstanceStatus::Cancelled);

    $tenant = app(TenantConnection::class)->get();
    $tasks = $tenant->table('workflow_tasks')->where('instance_id', $instanceId)->get();

    foreach ($tasks as $task) {
        expect($task->status)->toBe('skipped');
    }

    $event = $tenant->table('workflow_events')->where('instance_id', $instanceId)->where('event', 'cancelled')->first();

    expect($event->actor_employee_id)->toBe($chain['requester'])
        ->and($event->comment)->toBe('withdrawn')
        ->and(count(json_decode((string) $event->data, true)['skipped_task_ids']))->toBe(2);

    try {
        $taskId = (string) $tasks->first()->id;
        app(WorkflowGate::class)->decide($taskId, $chain['e1'], WorkflowVerdict::Approve);
        $this->fail('Expected WorkflowActionRefused.');
    } catch (WorkflowActionRefused $e) {
        expect($e->reason)->toBe('instanceClosed');
    }

    try {
        app(WorkflowGate::class)->cancel($instanceId);
        $this->fail('Expected WorkflowActionRefused.');
    } catch (WorkflowActionRefused $e) {
        expect($e->reason)->toBe('instanceClosed');
    }
});

it('refuses instance() and cancel() for an unknown instance id', function () {
    try {
        app(WorkflowGate::class)->instance('unknown');
        $this->fail('Expected WorkflowActionRefused.');
    } catch (WorkflowActionRefused $e) {
        expect($e->reason)->toBe('unknownInstance');
    }
});
