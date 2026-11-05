<?php

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Workflow\WorkflowApprovals;
use Flexipos\Contracts\Workflow\WorkflowContext;
use Flexipos\Contracts\Workflow\WorkflowEntityType;
use Flexipos\Contracts\Workflow\WorkflowGate;
use Flexipos\Contracts\Workflow\WorkflowOutcome;
use Flexipos\Contracts\Workflow\WorkflowVerdict;
use Flexipos\Workflow\Support\TenantConnection;
use Flexipos\Workflow\Tests\Fixtures\FixtureApproval;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class);

require_once __DIR__.'/../Support/workflow-fixture.php';

/*
|--------------------------------------------------------------------------
| Proof of pipeline (T-020 AC-27)
|--------------------------------------------------------------------------
|
| This is a proof-of-pipeline fixture, exercised end to end through the
| console commands and the published contract together - not a real
| business workflow (AC-28). Nothing here is a template for discounts,
| expenses, purchase orders, payroll or leave approval; each of those
| becomes real only when the module that owns it ships and calls
| WorkflowGate itself.
*/

beforeEach(function () {
    AccessTenantFixture::boot();
    workflowEnterTenant();
});

afterEach(function () {
    AccessTenantFixture::forget();
});

it('proves the pipeline end to end: definition, no-match, match, approve chain, reject at each step', function () {
    $chain = workflowSeedChain();

    $path = storage_path('app'.DIRECTORY_SEPARATOR.'wf-pipeline-fixture.json');
    File::put($path, FixtureApproval::json($chain['roleOne'], $chain['roleTwo']));

    tenancy()->leave();

    expect(Artisan::call('workflow:define', [
        'file' => $path,
        '--tenant' => 'tenant-a',
        '--as' => $chain['requester'],
    ]))->toBe(0);

    expect(Artisan::call('workflow:definitions', ['--tenant' => 'tenant-a']))->toBe(0)
        ->and(Artisan::output())->toContain('fixture-approval');

    workflowEnterTenant();

    $noMatch = app(WorkflowGate::class)->evaluate(
        WorkflowEntityType::Custom,
        new WorkflowContext((string) Str::ulid(), $chain['requester'], null, '500')
    );

    expect($noMatch->outcome)->toBe(WorkflowOutcome::NotRequired);

    $entityId = (string) Str::ulid();
    $decision = app(WorkflowGate::class)->evaluate(
        WorkflowEntityType::Custom,
        new WorkflowContext($entityId, $chain['requester'], null, '5000')
    );

    expect($decision->outcome)->toBe(WorkflowOutcome::Pending);

    $instanceId = $decision->instanceId;

    tenancy()->leave();

    expect(Artisan::call('workflow:pending', ['--tenant' => 'tenant-a']))->toBe(0);

    // Artisan::output() drains a BufferedOutput - read it once.
    $pendingOutput = Artisan::output();

    expect($pendingOutput)->toContain($chain['e1'])
        ->and($pendingOutput)->toContain($chain['e2']);

    workflowEnterTenant();
    $taskE1 = (string) app(TenantConnection::class)->get()->table('workflow_tasks')
        ->where('instance_id', $instanceId)->where('assignee_employee_id', $chain['e1'])->value('id');
    tenancy()->leave();

    expect(Artisan::call('workflow:decide', [
        'task' => $taskE1, '--tenant' => 'tenant-a', '--as' => $chain['e1'], '--approve' => true,
    ]))->toBe(0);

    workflowEnterTenant();
    $instance = app(TenantConnection::class)->get()->table('workflow_instances')->where('id', $instanceId)->first();
    expect((int) $instance->current_step)->toBe(2);

    $taskE3 = (string) app(TenantConnection::class)->get()->table('workflow_tasks')
        ->where('instance_id', $instanceId)->where('assignee_employee_id', $chain['e3'])->value('id');
    $taskE4 = (string) app(TenantConnection::class)->get()->table('workflow_tasks')
        ->where('instance_id', $instanceId)->where('assignee_employee_id', $chain['e4'])->value('id');
    tenancy()->leave();

    expect(Artisan::call('workflow:decide', [
        'task' => $taskE3, '--tenant' => 'tenant-a', '--as' => $chain['e3'], '--approve' => true,
    ]))->toBe(0);

    workflowEnterTenant();
    expect(app(TenantConnection::class)->get()->table('workflow_instances')->where('id', $instanceId)->value('status'))->toBe('pending');
    tenancy()->leave();

    expect(Artisan::call('workflow:decide', [
        'task' => $taskE4, '--tenant' => 'tenant-a', '--as' => $chain['e4'], '--approve' => true,
    ]))->toBe(0);

    workflowEnterTenant();
    expect(app(TenantConnection::class)->get()->table('workflow_instances')->where('id', $instanceId)->value('status'))->toBe('approved');
    tenancy()->leave();

    expect(Artisan::call('workflow:history', ['instance' => $instanceId, '--tenant' => 'tenant-a']))->toBe(0)
        ->and(Artisan::output())->toContain('status=approved');

    // Reject path 1: rejected at step 1 (both votes reject).
    workflowEnterTenant();
    $rejectEntityOne = (string) Str::ulid();
    $rejectOne = app(WorkflowGate::class)->evaluate(
        WorkflowEntityType::Custom,
        new WorkflowContext($rejectEntityOne, $chain['requester'], null, '5000')
    );
    $taskE1b = (string) app(TenantConnection::class)->get()->table('workflow_tasks')
        ->where('instance_id', $rejectOne->instanceId)->where('assignee_employee_id', $chain['e1'])->value('id');
    $taskE2b = (string) app(TenantConnection::class)->get()->table('workflow_tasks')
        ->where('instance_id', $rejectOne->instanceId)->where('assignee_employee_id', $chain['e2'])->value('id');

    app(WorkflowApprovals::class)->decide($taskE1b, $chain['e1'], WorkflowVerdict::Reject);
    $afterReject = app(WorkflowApprovals::class)->decide($taskE2b, $chain['e2'], WorkflowVerdict::Reject);

    expect($afterReject->status->value)->toBe('rejected')
        ->and($afterReject->onReject->value)->toBe('stop');

    // Reject path 2: rejected at step 2 (one rejection is enough under all).
    $rejectEntityTwo = (string) Str::ulid();
    $rejectTwo = app(WorkflowGate::class)->evaluate(
        WorkflowEntityType::Custom,
        new WorkflowContext($rejectEntityTwo, $chain['requester'], null, '5000')
    );
    $taskE1c = (string) app(TenantConnection::class)->get()->table('workflow_tasks')
        ->where('instance_id', $rejectTwo->instanceId)->where('assignee_employee_id', $chain['e1'])->value('id');
    app(WorkflowApprovals::class)->decide($taskE1c, $chain['e1'], WorkflowVerdict::Approve);

    $taskE3c = (string) app(TenantConnection::class)->get()->table('workflow_tasks')
        ->where('instance_id', $rejectTwo->instanceId)->where('assignee_employee_id', $chain['e3'])->value('id');
    $afterStepTwoReject = app(WorkflowApprovals::class)->decide($taskE3c, $chain['e3'], WorkflowVerdict::Reject);

    expect($afterStepTwoReject->status->value)->toBe('rejected');

    File::delete($path);
});
