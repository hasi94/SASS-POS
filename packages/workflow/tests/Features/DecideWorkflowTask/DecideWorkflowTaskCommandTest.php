<?php

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Workflow\WorkflowContext;
use Flexipos\Contracts\Workflow\WorkflowEntityType;
use Flexipos\Contracts\Workflow\WorkflowGate;
use Flexipos\Workflow\Features\RegisterWorkflowDefinition\RegisterWorkflowDefinition;
use Flexipos\Workflow\Features\RegisterWorkflowDefinition\RegisterWorkflowDefinitionHandler;
use Flexipos\Workflow\Support\TenantConnection;
use Flexipos\Workflow\Tests\Fixtures\FixtureApproval;
use Illuminate\Support\Facades\Artisan;
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

it('approves and rejects through the command, with --comment, and refuses both or neither flag (AC-25)', function () {
    workflowEnterTenant();
    $chain = workflowSeedChain();

    app(RegisterWorkflowDefinitionHandler::class)->handle(new RegisterWorkflowDefinition(
        FixtureApproval::json($chain['roleOne'], $chain['roleTwo']),
    ));

    $context = new WorkflowContext((string) Str::ulid(), $chain['requester'], null, '5000');
    $instanceId = app(WorkflowGate::class)->evaluate(WorkflowEntityType::Custom, $context)->instanceId;

    $taskId = (string) app(TenantConnection::class)->get()->table('workflow_tasks')
        ->where('instance_id', $instanceId)->where('assignee_employee_id', $chain['e1'])->value('id');

    tenancy()->leave();

    expect(Artisan::call('workflow:decide', [
        'task' => $taskId,
        '--tenant' => 'tenant-a',
        '--as' => $chain['e1'],
        '--approve' => true,
        '--comment' => 'ok',
    ]))->toBe(0);

    expect(Artisan::call('workflow:decide', [
        'task' => $taskId,
        '--tenant' => 'tenant-a',
        '--as' => $chain['e1'],
    ]))->toBe(1);

    expect(Artisan::call('workflow:decide', [
        'task' => $taskId,
        '--tenant' => 'tenant-a',
        '--as' => $chain['e1'],
        '--approve' => true,
        '--reject' => true,
    ]))->toBe(1);
});

it('exits 1 and prints the reason on a refusal', function () {
    expect(Artisan::call('workflow:decide', [
        'task' => 'unknown',
        '--tenant' => 'tenant-a',
        '--as' => 'nobody',
        '--approve' => true,
    ]))->toBe(1);
});
