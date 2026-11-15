<?php

use Flexipos\Access\Tests\Support\AccessSeed;
use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Workflow\WorkflowContext;
use Flexipos\Contracts\Workflow\WorkflowEntityType;
use Flexipos\Contracts\Workflow\WorkflowEvaluationFailed;
use Flexipos\Contracts\Workflow\WorkflowGate;
use Flexipos\Contracts\Workflow\WorkflowOutcome;
use Flexipos\Workflow\Features\RegisterWorkflowDefinition\RegisterWorkflowDefinition;
use Flexipos\Workflow\Features\RegisterWorkflowDefinition\RegisterWorkflowDefinitionHandler;
use Flexipos\Workflow\Support\TenantConnection;
use Flexipos\Workflow\Tests\Fixtures\FixtureApproval;
use Flexipos\Workflow\Tests\Support\FixtureGatedAction;
use Illuminate\Support\Facades\Schema;
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

function wfDefineFixture(string $roleOne, string $roleTwo, string $threshold = '1000'): void
{
    app(RegisterWorkflowDefinitionHandler::class)->handle(new RegisterWorkflowDefinition(
        FixtureApproval::json($roleOne, $roleTwo, $threshold),
        registeredBy: null,
    ));
}

function wfContext(string $requester, string $amount): WorkflowContext
{
    return new WorkflowContext((string) Str::ulid(), $requester, null, $amount);
}

function wfInstanceSideTablesEmpty(): bool
{
    $tenant = app(TenantConnection::class)->get();

    return $tenant->table('workflow_instances')->count() === 0
        && $tenant->table('workflow_tasks')->count() === 0
        && $tenant->table('workflow_events')->count() === 0;
}

it('returns NotRequired and writes nothing when the amount does not match', function () {
    $chain = workflowSeedChain();
    wfDefineFixture($chain['roleOne'], $chain['roleTwo']);

    $decision = app(WorkflowGate::class)->evaluate(WorkflowEntityType::Custom, wfContext($chain['requester'], '500'));

    expect($decision->outcome)->toBe(WorkflowOutcome::NotRequired)
        ->and(wfInstanceSideTablesEmpty())->toBeTrue();
});

it('returns NotRequired for an entity type with no definition', function () {
    $decision = app(WorkflowGate::class)->evaluate(WorkflowEntityType::Expense, wfContext((string) Str::ulid(), '5000'));

    expect($decision->outcome)->toBe(WorkflowOutcome::NotRequired);
});

it('creates a Pending instance on a match, with step 1s tasks and the started/assigned events', function () {
    $chain = workflowSeedChain();
    wfDefineFixture($chain['roleOne'], $chain['roleTwo']);

    $decision = app(WorkflowGate::class)->evaluate(WorkflowEntityType::Custom, wfContext($chain['requester'], '5000'));

    expect($decision->outcome)->toBe(WorkflowOutcome::Pending)
        ->and($decision->instanceId)->not->toBeNull()
        ->and($decision->definitionKey)->toBe('fixture-approval');

    $tenant = app(TenantConnection::class)->get();
    $instance = $tenant->table('workflow_instances')->where('id', $decision->instanceId)->first();

    expect($instance->status)->toBe('pending')
        ->and((int) $instance->current_step)->toBe(1)
        ->and((float) $instance->amount)->toBe(5000.0)
        ->and((int) $instance->definition_version)->toBe(1)
        ->and(json_decode((string) $instance->context, true))->toBe(['amount' => '5000']);

    $tasks = $tenant->table('workflow_tasks')->where('instance_id', $decision->instanceId)->get();

    expect($tasks)->toHaveCount(2);

    $events = $tenant->table('workflow_events')->where('instance_id', $decision->instanceId)->orderBy('id')->pluck('event')->all();

    expect($events)->toBe(['started', 'assigned', 'assigned']);
});

it('throws conditionNotEvaluable when the context has no amount, and writes nothing', function () {
    $chain = workflowSeedChain();
    wfDefineFixture($chain['roleOne'], $chain['roleTwo']);

    $context = new WorkflowContext((string) Str::ulid(), $chain['requester']);

    try {
        app(WorkflowGate::class)->evaluate(WorkflowEntityType::Custom, $context);
        $this->fail('Expected WorkflowEvaluationFailed.');
    } catch (WorkflowEvaluationFailed $e) {
        expect($e->cause)->toBe('conditionNotEvaluable');
    }

    expect(wfInstanceSideTablesEmpty())->toBeTrue();
});

it('throws engineError and rolls back when the engine itself fails', function () {
    $chain = workflowSeedChain();
    wfDefineFixture($chain['roleOne'], $chain['roleTwo']);

    Schema::connection(AccessTenantFixture::connectionName())->drop('workflow_events');

    try {
        app(WorkflowGate::class)->evaluate(WorkflowEntityType::Custom, wfContext($chain['requester'], '5000'));
        $this->fail('Expected WorkflowEvaluationFailed.');
    } catch (WorkflowEvaluationFailed $e) {
        expect($e->cause)->toBe('engineError');
    }

    $tenant = app(TenantConnection::class)->get();

    expect($tenant->table('workflow_instances')->count())->toBe(0)
        ->and($tenant->table('workflow_tasks')->count())->toBe(0);
});

it('never runs the gated action on a throw, and does on NotRequired (control)', function () {
    $chain = workflowSeedChain();
    wfDefineFixture($chain['roleOne'], $chain['roleTwo']);

    $action = new FixtureGatedAction(app(WorkflowGate::class));

    $action->run(WorkflowEntityType::Expense, wfContext($chain['requester'], '5000'));
    expect($action->ranCount)->toBe(1);

    try {
        $action->run(WorkflowEntityType::Custom, new WorkflowContext((string) Str::ulid(), $chain['requester']));
    } catch (WorkflowEvaluationFailed) {
        // expected
    }

    expect($action->ranCount)->toBe(1);
});

it('picks the lower priority definition, and the other one when priorities are swapped (AC-16)', function () {
    $chain = workflowSeedChain();

    app(RegisterWorkflowDefinitionHandler::class)->handle(new RegisterWorkflowDefinition(
        json_encode(array_merge(json_decode(FixtureApproval::json($chain['roleOne'], $chain['roleTwo']), true), ['key' => 'fixture-low', 'priority' => 10])),
    ));
    app(RegisterWorkflowDefinitionHandler::class)->handle(new RegisterWorkflowDefinition(
        json_encode(array_merge(json_decode(FixtureApproval::json($chain['roleOne'], $chain['roleTwo']), true), ['key' => 'fixture-high', 'priority' => 20])),
    ));

    $decision = app(WorkflowGate::class)->evaluate(WorkflowEntityType::Custom, wfContext($chain['requester'], '5000'));

    expect($decision->definitionKey)->toBe('fixture-low');
});

it('throws ambiguousDefinitions when two definitions match at the same priority', function () {
    $chain = workflowSeedChain();

    app(RegisterWorkflowDefinitionHandler::class)->handle(new RegisterWorkflowDefinition(
        json_encode(array_merge(json_decode(FixtureApproval::json($chain['roleOne'], $chain['roleTwo']), true), ['key' => 'fixture-tie-a'])),
    ));
    app(RegisterWorkflowDefinitionHandler::class)->handle(new RegisterWorkflowDefinition(
        json_encode(array_merge(json_decode(FixtureApproval::json($chain['roleOne'], $chain['roleTwo']), true), ['key' => 'fixture-tie-b'])),
    ));

    try {
        app(WorkflowGate::class)->evaluate(WorkflowEntityType::Custom, wfContext($chain['requester'], '5000'));
        $this->fail('Expected WorkflowEvaluationFailed.');
    } catch (WorkflowEvaluationFailed $e) {
        expect($e->cause)->toBe('ambiguousDefinitions');
    }
});

it('resolves approvers at step-activation time, not at definition time: a membership change after instance 1 is seen by instance 2 only (AC-9)', function () {
    $chain = workflowSeedChain();
    wfDefineFixture($chain['roleOne'], $chain['roleTwo']);

    $first = app(WorkflowGate::class)->evaluate(WorkflowEntityType::Custom, wfContext($chain['requester'], '5000'));

    $e5 = AccessSeed::employee('e5');
    AccessSeed::assign($e5, $chain['roleOne']);

    $second = app(WorkflowGate::class)->evaluate(WorkflowEntityType::Custom, wfContext($chain['requester'], '5000'));

    $tenant = app(TenantConnection::class)->get();

    $firstAssignees = $tenant->table('workflow_tasks')->where('instance_id', $first->instanceId)->pluck('assignee_employee_id')->all();
    $secondAssignees = $tenant->table('workflow_tasks')->where('instance_id', $second->instanceId)->pluck('assignee_employee_id')->all();

    expect($firstAssignees)->not->toContain($e5)
        ->and($secondAssignees)->toContain($e5);
});

it('fails closed on a branch-scoped definition when the context carries no branch, and matches only the branch named (Ruling 4)', function () {
    $chain = workflowSeedChain();
    $branchA = AccessSeed::branch();
    $branchB = AccessSeed::branch();

    app(RegisterWorkflowDefinitionHandler::class)->handle(new RegisterWorkflowDefinition(
        json_encode(array_merge(json_decode(FixtureApproval::json($chain['roleOne'], $chain['roleTwo']), true), [
            'key' => 'fixture-branch-scoped',
            'branch_ids' => [$branchA],
        ])),
    ));

    // Another branch: the definition does not match, no error.
    $other = app(WorkflowGate::class)->evaluate(WorkflowEntityType::Custom, new WorkflowContext((string) Str::ulid(), $chain['requester'], $branchB, '5000'));
    expect($other->outcome)->toBe(WorkflowOutcome::NotRequired);

    // No branch at all: fails closed, never a silent non-match.
    try {
        app(WorkflowGate::class)->evaluate(WorkflowEntityType::Custom, wfContext($chain['requester'], '5000'));
        $this->fail('Expected WorkflowEvaluationFailed.');
    } catch (WorkflowEvaluationFailed $e) {
        expect($e->cause)->toBe('conditionNotEvaluable');
    }

    // The named branch: matches.
    $matched = app(WorkflowGate::class)->evaluate(WorkflowEntityType::Custom, new WorkflowContext((string) Str::ulid(), $chain['requester'], $branchA, '5000'));
    expect($matched->outcome)->toBe(WorkflowOutcome::Pending)
        ->and($matched->definitionKey)->toBe('fixture-branch-scoped');
});

it('refuses a second evaluate while an instance is pending, and allows one after a cancel (Ruling 9)', function () {
    $chain = workflowSeedChain();
    wfDefineFixture($chain['roleOne'], $chain['roleTwo']);

    $entityId = (string) Str::ulid();
    $context = new WorkflowContext($entityId, $chain['requester'], null, '5000');

    $first = app(WorkflowGate::class)->evaluate(WorkflowEntityType::Custom, $context);

    try {
        app(WorkflowGate::class)->evaluate(WorkflowEntityType::Custom, $context);
        $this->fail('Expected WorkflowEvaluationFailed.');
    } catch (WorkflowEvaluationFailed $e) {
        expect($e->cause)->toBe('alreadyPending');
    }

    app(WorkflowGate::class)->cancel($first->instanceId);

    $second = app(WorkflowGate::class)->evaluate(WorkflowEntityType::Custom, $context);

    expect($second->outcome)->toBe(WorkflowOutcome::Pending)
        ->and($second->instanceId)->not->toBe($first->instanceId);
});
