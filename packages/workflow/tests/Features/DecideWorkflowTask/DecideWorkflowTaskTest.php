<?php

use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Workflow\WorkflowActionRefused;
use Flexipos\Contracts\Workflow\WorkflowApprovals;
use Flexipos\Contracts\Workflow\WorkflowContext;
use Flexipos\Contracts\Workflow\WorkflowEntityType;
use Flexipos\Contracts\Workflow\WorkflowEvaluationFailed;
use Flexipos\Contracts\Workflow\WorkflowGate;
use Flexipos\Contracts\Workflow\WorkflowInstanceStatus;
use Flexipos\Contracts\Workflow\WorkflowOnReject;
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

    app(WorkflowApprovals::class)->decide($taskE1, $chain['e1'], WorkflowVerdict::Approve, 'looks fine');

    $task = app(TenantConnection::class)->get()->table('workflow_tasks')->where('id', $taskE1)->first();

    expect($task->status)->toBe('approved')
        ->and($task->decided_by)->toBe($chain['e1'])
        ->and($task->comment)->toBe('looks fine')
        ->and($task->decided_at)->not->toBeNull();

    try {
        app(WorkflowApprovals::class)->decide($taskE1, $chain['e1'], WorkflowVerdict::Approve);
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

    app(WorkflowApprovals::class)->decide($taskE1, $chain['e1'], WorkflowVerdict::Approve);

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
    app(WorkflowApprovals::class)->decide($taskE1, $chain['e1'], WorkflowVerdict::Approve);

    $taskE3 = wfTaskFor($instanceId, $chain['e3']);
    $taskE4 = wfTaskFor($instanceId, $chain['e4']);

    $afterE3 = app(WorkflowApprovals::class)->decide($taskE3, $chain['e3'], WorkflowVerdict::Approve);
    expect($afterE3->status)->toBe(WorkflowInstanceStatus::Pending);

    $afterE4 = app(WorkflowApprovals::class)->decide($taskE4, $chain['e4'], WorkflowVerdict::Approve);
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

    $afterE1 = app(WorkflowApprovals::class)->decide($taskE1, $chain['e1'], WorkflowVerdict::Reject);
    expect($afterE1->status)->toBe(WorkflowInstanceStatus::Pending);

    $afterE2 = app(WorkflowApprovals::class)->decide($taskE2, $chain['e2'], WorkflowVerdict::Reject);
    expect($afterE2->status)->toBe(WorkflowInstanceStatus::Rejected)
        ->and($afterE2->onReject->value)->toBe('stop');
});

it('rejects a quorum-all step immediately on one rejection, skipping the rest', function () {
    $chain = workflowSeedChain();
    $instanceId = wfStartInstance($chain);
    $taskE1 = wfTaskFor($instanceId, $chain['e1']);
    app(WorkflowApprovals::class)->decide($taskE1, $chain['e1'], WorkflowVerdict::Approve);

    $taskE3 = wfTaskFor($instanceId, $chain['e3']);
    $taskE4 = wfTaskFor($instanceId, $chain['e4']);

    $afterE3 = app(WorkflowApprovals::class)->decide($taskE3, $chain['e3'], WorkflowVerdict::Reject);

    expect($afterE3->status)->toBe(WorkflowInstanceStatus::Rejected)
        ->and(app(TenantConnection::class)->get()->table('workflow_tasks')->where('id', $taskE4)->value('status'))->toBe('skipped');
});

it('refuses a decision from a non-assignee, and on an unknown task', function () {
    $chain = workflowSeedChain();
    $instanceId = wfStartInstance($chain);
    $taskE1 = wfTaskFor($instanceId, $chain['e1']);

    try {
        app(WorkflowApprovals::class)->decide($taskE1, $chain['e2'], WorkflowVerdict::Approve);
        $this->fail('Expected WorkflowActionRefused.');
    } catch (WorkflowActionRefused $e) {
        expect($e->reason)->toBe('notAssignee');
    }

    try {
        app(WorkflowApprovals::class)->decide('unknown-task-id', $chain['e1'], WorkflowVerdict::Approve);
        $this->fail('Expected WorkflowActionRefused.');
    } catch (WorkflowActionRefused $e) {
        expect($e->reason)->toBe('unknownTask');
    }
});

it('refuses a decision once the instance has reached each terminal status (AC-20)', function (string $status) {
    $chain = workflowSeedChain();
    $instanceId = wfStartInstance($chain);
    $taskE1 = wfTaskFor($instanceId, $chain['e1']);
    $taskE2 = wfTaskFor($instanceId, $chain['e2']);

    match ($status) {
        'approved' => (function () use ($chain, $instanceId, $taskE1): void {
            app(WorkflowApprovals::class)->decide($taskE1, $chain['e1'], WorkflowVerdict::Approve);
            app(WorkflowApprovals::class)->decide(wfTaskFor($instanceId, $chain['e3']), $chain['e3'], WorkflowVerdict::Approve);
            app(WorkflowApprovals::class)->decide(wfTaskFor($instanceId, $chain['e4']), $chain['e4'], WorkflowVerdict::Approve);
        })(),
        'rejected' => (function () use ($chain, $taskE1, $taskE2): void {
            app(WorkflowApprovals::class)->decide($taskE1, $chain['e1'], WorkflowVerdict::Reject);
            app(WorkflowApprovals::class)->decide($taskE2, $chain['e2'], WorkflowVerdict::Reject);
        })(),
        'cancelled' => app(WorkflowGate::class)->cancel($instanceId),
        'expired' => app(TenantConnection::class)->get()->table('workflow_instances')
            ->where('id', $instanceId)->update(['status' => 'expired']),
        default => throw new LogicException("unhandled status in test: {$status}"),
    };

    try {
        app(WorkflowApprovals::class)->decide($taskE1, $chain['e1'], WorkflowVerdict::Approve);
        $this->fail('Expected WorkflowActionRefused.');
    } catch (WorkflowActionRefused $e) {
        expect($e->reason)->toBe('instanceClosed');
    }
})->with(['approved', 'rejected', 'cancelled', 'expired']);

it('writes the exact AC-18 event sequence for a full approve run, with actor per Ruling 8', function () {
    $chain = workflowSeedChain();
    $instanceId = wfStartInstance($chain);
    $taskE1 = wfTaskFor($instanceId, $chain['e1']);

    app(WorkflowApprovals::class)->decide($taskE1, $chain['e1'], WorkflowVerdict::Approve);

    $taskE3 = wfTaskFor($instanceId, $chain['e3']);
    $taskE4 = wfTaskFor($instanceId, $chain['e4']);

    app(WorkflowApprovals::class)->decide($taskE3, $chain['e3'], WorkflowVerdict::Approve);
    app(WorkflowApprovals::class)->decide($taskE4, $chain['e4'], WorkflowVerdict::Approve);

    $events = app(TenantConnection::class)->get()->table('workflow_events')
        ->where('instance_id', $instanceId)
        ->orderBy('id')
        ->get();

    expect($events->pluck('event')->all())->toBe([
        'started', 'assigned', 'assigned', 'approved', 'assigned', 'assigned', 'approved', 'approved', 'completed',
    ]);

    expect($events[0]->actor_employee_id)->toBe($chain['requester'])
        ->and($events[1]->actor_employee_id)->toBeNull()
        ->and($events[2]->actor_employee_id)->toBeNull()
        ->and($events[3]->actor_employee_id)->toBe($chain['e1'])
        ->and($events[4]->actor_employee_id)->toBeNull()
        ->and($events[5]->actor_employee_id)->toBeNull()
        ->and($events[6]->actor_employee_id)->toBe($chain['e3'])
        ->and($events[7]->actor_employee_id)->toBe($chain['e4'])
        ->and($events[8]->actor_employee_id)->toBe($chain['e4']);
});

it('writes a rejected vote event while the step is still open (quorum any, one of two), and leaves it pending', function () {
    $chain = workflowSeedChain();
    $instanceId = wfStartInstance($chain);
    $taskE1 = wfTaskFor($instanceId, $chain['e1']);
    $taskE2 = wfTaskFor($instanceId, $chain['e2']);

    $after = app(WorkflowApprovals::class)->decide($taskE1, $chain['e1'], WorkflowVerdict::Reject, 'needs more info');

    expect($after->status)->toBe(WorkflowInstanceStatus::Pending);

    $tenant = app(TenantConnection::class)->get();

    expect($tenant->table('workflow_tasks')->where('id', $taskE1)->value('status'))->toBe('rejected')
        ->and($tenant->table('workflow_tasks')->where('id', $taskE2)->value('status'))->toBe('pending');

    $event = $tenant->table('workflow_events')
        ->where('instance_id', $instanceId)
        ->where('event', 'rejected')
        ->where('task_id', $taskE1)
        ->first();

    expect($event)->not->toBeNull()
        ->and($event->actor_employee_id)->toBe($chain['e1'])
        ->and($event->comment)->toBe('needs more info');
});

it('reports on_reject=return_to_requester on the instance once rejected (AC-19)', function () {
    $chain = workflowSeedChain();

    app(RegisterWorkflowDefinitionHandler::class)->handle(new RegisterWorkflowDefinition(
        json_encode(array_merge(
            json_decode(FixtureApproval::json($chain['roleOne'], $chain['roleTwo']), true),
            ['on_reject' => 'return_to_requester'],
        )),
    ));

    $context = new WorkflowContext((string) Str::ulid(), $chain['requester'], null, '5000');
    $instanceId = (string) app(WorkflowGate::class)->evaluate(WorkflowEntityType::Custom, $context)->instanceId;

    $taskE1 = wfTaskFor($instanceId, $chain['e1']);
    $taskE2 = wfTaskFor($instanceId, $chain['e2']);

    app(WorkflowApprovals::class)->decide($taskE1, $chain['e1'], WorkflowVerdict::Reject);
    $afterE2 = app(WorkflowApprovals::class)->decide($taskE2, $chain['e2'], WorkflowVerdict::Reject);

    expect($afterE2->status)->toBe(WorkflowInstanceStatus::Rejected)
        ->and($afterE2->onReject)->toBe(WorkflowOnReject::ReturnToRequester);
});

it('refuses cleanly with WorkflowEvaluationFailed when the next step is unassignable (W-4)', function () {
    $chain = workflowSeedChain();
    $instanceId = wfStartInstance($chain);
    $taskE1 = wfTaskFor($instanceId, $chain['e1']);

    // A hand-mutated row: the validator never allows this at write time
    // (Ruling 6c), but a corrupted row must still fail closed at read time.
    $tenant = app(TenantConnection::class)->get();
    $definitionId = (string) $tenant->table('workflow_instances')->where('id', $instanceId)->value('definition_id');

    $tenant->table('workflow_steps')
        ->where('definition_id', $definitionId)
        ->where('sequence', 2)
        ->update(['approver_type' => 'manager_of_requester']);

    try {
        app(WorkflowApprovals::class)->decide($taskE1, $chain['e1'], WorkflowVerdict::Approve);
        $this->fail('Expected WorkflowEvaluationFailed.');
    } catch (WorkflowEvaluationFailed $e) {
        expect($e->cause)->toBe('approverTypeUnavailable');
    }
});

it('leaves the instance pending and unassignedStep=2 when step 2s holders are removed before step 1 is approved (AC-12)', function () {
    $chain = workflowSeedChain();
    $instanceId = wfStartInstance($chain);
    $taskE1 = wfTaskFor($instanceId, $chain['e1']);

    AccessSeed::connection()->table('employee_roles')->where('role_id', $chain['roleTwo'])->delete();

    $afterE1 = app(WorkflowApprovals::class)->decide($taskE1, $chain['e1'], WorkflowVerdict::Approve);

    expect($afterE1->unassignedStep)->toBe(2)
        ->and(app(TenantConnection::class)->get()->table('workflow_tasks')->where('instance_id', $instanceId)->where('step_sequence', 2)->count())->toBe(0);
});
