<?php

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Workflow\WorkflowContext;
use Flexipos\Contracts\Workflow\WorkflowEntityType;
use Flexipos\Contracts\Workflow\WorkflowGate;
use Flexipos\Contracts\Workflow\WorkflowVerdict;
use Flexipos\Workflow\Features\ListPendingWorkflowTasks\ListPendingWorkflowTasks;
use Flexipos\Workflow\Features\ListPendingWorkflowTasks\ListPendingWorkflowTasksHandler;
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
});

afterEach(function () {
    AccessTenantFixture::forget();
});

it('lists only pending tasks on pending instances, filters by assignee, and excludes another tenant (AC-24)', function () {
    workflowEnterTenant('tenant-a');
    $chain = workflowSeedChain();

    app(RegisterWorkflowDefinitionHandler::class)->handle(new RegisterWorkflowDefinition(
        FixtureApproval::json($chain['roleOne'], $chain['roleTwo']),
    ));

    $context = new WorkflowContext((string) Str::ulid(), $chain['requester'], null, '5000');
    $instanceId = app(WorkflowGate::class)->evaluate(WorkflowEntityType::Custom, $context)->instanceId;

    $taskE1 = (string) app(TenantConnection::class)->get()->table('workflow_tasks')
        ->where('instance_id', $instanceId)->where('assignee_employee_id', $chain['e1'])->value('id');

    app(WorkflowGate::class)->decide($taskE1, $chain['e1'], WorkflowVerdict::Approve);

    $rows = app(ListPendingWorkflowTasksHandler::class)->handle(new ListPendingWorkflowTasks);
    $assignees = array_map(static fn (object $r): string => (string) $r->assignee, $rows);

    expect($assignees)->not->toContain($chain['e1'])
        ->and($assignees)->toContain($chain['e3'])
        ->and($assignees)->toContain($chain['e4']);

    $filtered = app(ListPendingWorkflowTasksHandler::class)->handle(new ListPendingWorkflowTasks($chain['e3']));

    expect(array_map(static fn (object $r): string => (string) $r->assignee, $filtered))->toBe([$chain['e3']]);

    AccessTenantFixture::switchTo('tenant-b');

    $tenantBRows = app(ListPendingWorkflowTasksHandler::class)->handle(new ListPendingWorkflowTasks);

    expect($tenantBRows)->toBe([]);
});
