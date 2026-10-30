<?php

use Flexipos\Access\Tests\Support\AccessSeed;
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

/**
 * @param  array<string, string>  $chain
 */
function wfStartInstance(array $chain, string $amount = '5000'): string
{
    app(RegisterWorkflowDefinitionHandler::class)->handle(new RegisterWorkflowDefinition(
        FixtureApproval::json($chain['roleOne'], $chain['roleTwo']),
    ));

    $context = new WorkflowContext((string) Str::ulid(), $chain['requester'], null, $amount);

    return (string) app(WorkflowGate::class)->evaluate(WorkflowEntityType::Custom, $context)->instanceId;
}

function wfTaskFor(string $instanceId, string $employeeId): string
{
    return (string) app(TenantConnection::class)->get()->table('workflow_tasks')
        ->where('instance_id', $instanceId)
        ->where('assignee_employee_id', $employeeId)
        ->value('id');
}

it('records decided_by/decided_at/comment on approval, refuses a second decision (AC-17)', function () {
    $chain = workflowSeedChain();
    $instanceId = wfStartInstance($chain);
    $taskE1 = wfTaskFor($instanceId, $chain['e1']);

    app(WorkflowGate::class)->decide($taskE1, $chain['e1'], WorkflowVerdict::Approve, 'looks fine');

    $task = app(TenantConnection::class)->get()->table('workflow_tasks')->where('id', $taskE1)->first();

    expect($task->status)->toBe('approved')
        ->and($task->decided_by)->toBe($chain['e1'])
        ->and($task->comment)->toBe('looks fine')
        ->and($task->decided_at)->not->toBeNull();

    try {
        app(WorkflowGate::class)->decide($taskE1, $chain['e1'], WorkflowVerdict::Approve);
        $this->fail('Expected WorkflowActionRefused.');
    } catch (WorkflowActionRefused $e) {
        expect($e->reason)->toBe('taskNotPending');
    }
});

it('advances to step 2 once quorum any is satisfied, skipping the other step-1 task (AC-11)', function () {
    $chain = workflowSeedChain();
    $instanceId = wfStartInstance($chain);
    $taskE1 = wfTaskFor($instanceId, $chain['e1']);
    $taskE2 = wfTaskFor($instanceId, $chain['e2']);

    app(WorkflowGate::class)->decide($taskE1, $chain['e1'], WorkflowVerdict::Approve);

    $tenant = app(TenantConnection::class)->get();
    $instance = $tenant->table('workflow_instances')->where('id', $instanceId)->first();

    expect((int) $instance->current_step)->toBe(2)
        ->and($tenant->table('workflow_tasks')->where('id', $taskE2)->value('status'))->toBe('skipped');

    $stepTwoTasks = $tenant->table('workflow_tasks')->where('instance_id', $instanceId)->where('step_sequence', 2)->get();

    expect($stepTwoTasks)->toHaveCount(2);

    $event = $tenant->table('workflow_events')->where('instance_id', $instanceId)->where('event', 'approved')->first();
    $data = json_decode((string) $event->data, true);

    expect($data['advanced_to'])->toBe(2)
        ->and($data['skipped_task_ids'])->toBe([$taskE2]);
});

it('requires every resolved approver for quorum all, and reaches approved on the last (step 2)', function () {
    $chain = workflowSeedChain();
    $instanceId = wfStartInstance($chain);
    $taskE1 = wfTaskFor($instanceId, $chain['e1']);
    app(WorkflowGate::class)->decide($taskE1, $chain['e1'], WorkflowVerdict::Approve);

    $taskE3 = wfTaskFor($instanceId, $chain['e3']);
    $taskE4 = wfTaskFor($instanceId, $chain['e4']);

    $afterE3 = app(WorkflowGate::class)->decide($taskE3, $chain['e3'], WorkflowVerdict::Approve);
    expect($afterE3->status)->toBe(WorkflowInstanceStatus::Pending);

    $afterE4 = app(WorkflowGate::class)->decide($taskE4, $chain['e4'], WorkflowVerdict::Approve);
    expect($afterE4->status)->toBe(WorkflowInstanceStatus::Approved);

    $tenant = app(TenantConnection::class)->get();
    $instance = $tenant->table('workflow_instances')->where('id', $instanceId)->first();

    expect($instance->completed_at)->not->toBeNull();

    $completed = $tenant->table('workflow_events')->where('instance_id', $instanceId)->where('event', 'completed')->first();

    expect(json_decode((string) $completed->data, true)['status'])->toBe('approved');
});

it('rejects at step 1 only once quorum any is unreachable, and reports on_reject (AC-10, AC-19)', function () {
    $chain = workflowSeedChain();
    $instanceId = wfStartInstance($chain);
    $taskE1 = wfTaskFor($instanceId, $chain['e1']);
    $taskE2 = wfTaskFor($instanceId, $chain['e2']);

    $afterE1 = app(WorkflowGate::class)->decide($taskE1, $chain['e1'], WorkflowVerdict::Reject);
    expect($afterE1->status)->toBe(WorkflowInstanceStatus::Pending);

    $afterE2 = app(WorkflowGate::class)->decide($taskE2, $chain['e2'], WorkflowVerdict::Reject);
    expect($afterE2->status)->toBe(WorkflowInstanceStatus::Rejected)
        ->and($afterE2->onReject->value)->toBe('stop');
});

it('rejects a quorum-all step immediately on one rejection, skipping the rest', function () {
    $chain = workflowSeedChain();
    $instanceId = wfStartInstance($chain);
    $taskE1 = wfTaskFor($instanceId, $chain['e1']);
    app(WorkflowGate::class)->decide($taskE1, $chain['e1'], WorkflowVerdict::Approve);

    $taskE3 = wfTaskFor($instanceId, $chain['e3']);
    $taskE4 = wfTaskFor($instanceId, $chain['e4']);

    $afterE3 = app(WorkflowGate::class)->decide($taskE3, $chain['e3'], WorkflowVerdict::Reject);

    expect($afterE3->status)->toBe(WorkflowInstanceStatus::Rejected)
        ->and(app(TenantConnection::class)->get()->table('workflow_tasks')->where('id', $taskE4)->value('status'))->toBe('skipped');
});

it('refuses a decision for each terminal status, and a non-assignee, and an unknown task', function () {
    $chain = workflowSeedChain();
    $instanceId = wfStartInstance($chain);
    $taskE1 = wfTaskFor($instanceId, $chain['e1']);
    $taskE2 = wfTaskFor($instanceId, $chain['e2']);

    try {
        app(WorkflowGate::class)->decide($taskE1, $chain['e2'], WorkflowVerdict::Approve);
        $this->fail('Expected WorkflowActionRefused.');
    } catch (WorkflowActionRefused $e) {
        expect($e->reason)->toBe('notAssignee');
    }

    try {
        app(WorkflowGate::class)->decide('unknown-task-id', $chain['e1'], WorkflowVerdict::Approve);
        $this->fail('Expected WorkflowActionRefused.');
    } catch (WorkflowActionRefused $e) {
        expect($e->reason)->toBe('unknownTask');
    }

    app(WorkflowGate::class)->cancel($instanceId);

    try {
        app(WorkflowGate::class)->decide($taskE2, $chain['e2'], WorkflowVerdict::Approve);
        $this->fail('Expected WorkflowActionRefused.');
    } catch (WorkflowActionRefused $e) {
        expect($e->reason)->toBe('instanceClosed');
    }
});

it('leaves the instance pending and unassignedStep=2 when step 2s holders are removed before step 1 is approved (AC-12)', function () {
    $chain = workflowSeedChain();
    $instanceId = wfStartInstance($chain);
    $taskE1 = wfTaskFor($instanceId, $chain['e1']);

    AccessSeed::connection()->table('employee_roles')->where('role_id', $chain['roleTwo'])->delete();

    $afterE1 = app(WorkflowGate::class)->decide($taskE1, $chain['e1'], WorkflowVerdict::Approve);

    expect($afterE1->unassignedStep)->toBe(2)
        ->and(app(TenantConnection::class)->get()->table('workflow_tasks')->where('instance_id', $instanceId)->where('step_sequence', 2)->count())->toBe(0);
});
