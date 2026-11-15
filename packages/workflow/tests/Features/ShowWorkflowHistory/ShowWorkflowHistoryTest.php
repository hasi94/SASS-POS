<?php

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Contracts\Workflow\WorkflowContext;
use Flexipos\Contracts\Workflow\WorkflowEntityType;
use Flexipos\Contracts\Workflow\WorkflowGate;
use Flexipos\Workflow\Features\RegisterWorkflowDefinition\RegisterWorkflowDefinition;
use Flexipos\Workflow\Features\RegisterWorkflowDefinition\RegisterWorkflowDefinitionHandler;
use Flexipos\Workflow\Features\ShowWorkflowHistory\ShowWorkflowHistory;
use Flexipos\Workflow\Features\ShowWorkflowHistory\ShowWorkflowHistoryHandler;
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

it('shows the header and every event in order, naming the unassigned step when there is one', function () {
    $chain = workflowSeedChain();

    app(RegisterWorkflowDefinitionHandler::class)->handle(new RegisterWorkflowDefinition(
        FixtureApproval::json($chain['roleOne'], $chain['roleTwo']),
    ));

    $context = new WorkflowContext((string) Str::ulid(), $chain['requester'], null, '5000');
    $instanceId = app(WorkflowGate::class)->evaluate(WorkflowEntityType::Custom, $context)->instanceId;

    $result = app(ShowWorkflowHistoryHandler::class)->handle(new ShowWorkflowHistory($instanceId));

    expect($result->state->definitionKey)->toBe('fixture-approval')
        ->and($result->state->onReject->value)->toBe('stop')
        ->and(array_map(static fn (object $e): string => (string) $e->event, $result->events))->toBe(['started', 'assigned', 'assigned']);
});
