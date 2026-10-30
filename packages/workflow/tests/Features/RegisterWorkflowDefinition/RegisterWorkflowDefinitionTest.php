<?php

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Workflow\Exceptions\WorkflowDefinitionRefused;
use Flexipos\Workflow\Features\RegisterWorkflowDefinition\RegisterWorkflowDefinition;
use Flexipos\Workflow\Features\RegisterWorkflowDefinition\RegisterWorkflowDefinitionHandler;
use Flexipos\Workflow\Support\TenantConnection;
use Flexipos\Workflow\Tests\Fixtures\FixtureApproval;
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

it('writes the rows exactly: version 1, created_by/updated_by from --as, steps in order, ids folded upper case', function () {
    $chain = workflowSeedChain();

    $result = app(RegisterWorkflowDefinitionHandler::class)->handle(new RegisterWorkflowDefinition(
        FixtureApproval::json(strtolower($chain['roleOne']), strtolower($chain['roleTwo'])),
        registeredBy: $chain['requester'],
    ));

    expect($result->key)->toBe('fixture-approval')
        ->and($result->stepCount)->toBe(2);

    $tenant = app(TenantConnection::class)->get();
    $row = $tenant->table('workflow_definitions')->where('id', $result->id)->first();

    expect((int) $row->version)->toBe(1)
        ->and($row->created_by)->toBe($chain['requester'])
        ->and($row->updated_by)->toBe($chain['requester']);

    $steps = $tenant->table('workflow_steps')->where('definition_id', $result->id)->orderBy('sequence')->get();

    expect($steps)->toHaveCount(2)
        ->and((int) $steps[0]->sequence)->toBe(1)
        ->and((int) $steps[1]->sequence)->toBe(2);

    $ref = json_decode((string) $steps[0]->approver_ref, true);

    expect($ref['roles'][0])->toBe(strtoupper($chain['roleOne']));
});

it('refuses a second define with the same key, active, inactive or soft-deleted, and writes nothing (AC-2)', function () {
    $chain = workflowSeedChain();

    app(RegisterWorkflowDefinitionHandler::class)->handle(new RegisterWorkflowDefinition(
        FixtureApproval::json($chain['roleOne'], $chain['roleTwo']),
    ));

    $before = app(TenantConnection::class)->get()->table('workflow_definitions')->count();

    try {
        app(RegisterWorkflowDefinitionHandler::class)->handle(new RegisterWorkflowDefinition(
            FixtureApproval::json($chain['roleOne'], $chain['roleTwo']),
        ));
        $this->fail('Expected WorkflowDefinitionRefused.');
    } catch (WorkflowDefinitionRefused $e) {
        expect($e->reason)->toBe('keyAlreadyRegistered');
    }

    expect(app(TenantConnection::class)->get()->table('workflow_definitions')->count())->toBe($before);

    app(TenantConnection::class)->get()->table('workflow_definitions')->update(['is_active' => false]);

    try {
        app(RegisterWorkflowDefinitionHandler::class)->handle(new RegisterWorkflowDefinition(
            FixtureApproval::json($chain['roleOne'], $chain['roleTwo']),
        ));
        $this->fail('Expected WorkflowDefinitionRefused.');
    } catch (WorkflowDefinitionRefused $e) {
        expect($e->reason)->toBe('keyAlreadyRegistered');
    }

    app(TenantConnection::class)->get()->table('workflow_definitions')->update(['deleted_at' => now()->format('Y-m-d H:i:s.v')]);

    try {
        app(RegisterWorkflowDefinitionHandler::class)->handle(new RegisterWorkflowDefinition(
            FixtureApproval::json($chain['roleOne'], $chain['roleTwo']),
        ));
        $this->fail('Expected WorkflowDefinitionRefused.');
    } catch (WorkflowDefinitionRefused $e) {
        expect($e->reason)->toBe('keyAlreadyRegistered');
    }
});

it('stores sla_hours and escalate_to given in the file (AC-22)', function () {
    $chain = workflowSeedChain();
    $json = json_decode(FixtureApproval::json($chain['roleOne'], $chain['roleTwo']), true);
    $json['steps'][0]['sla_hours'] = 48;
    $json['steps'][0]['escalate_to'] = ['roles' => [$chain['roleTwo']]];

    $result = app(RegisterWorkflowDefinitionHandler::class)->handle(new RegisterWorkflowDefinition(json_encode($json)));

    $step = app(TenantConnection::class)->get()->table('workflow_steps')
        ->where('definition_id', $result->id)->where('sequence', 1)->first();

    expect((int) $step->sla_hours)->toBe(48)
        ->and(json_decode((string) $step->escalate_to, true))->toBe(['roles' => [$chain['roleTwo']]]);
});

it('writes nothing when the definition is refused at any stage', function () {
    $chain = workflowSeedChain();
    $json = json_decode(FixtureApproval::json($chain['roleOne'], $chain['roleTwo']), true);
    $json['steps'] = [];

    try {
        app(RegisterWorkflowDefinitionHandler::class)->handle(new RegisterWorkflowDefinition(json_encode($json)));
    } catch (WorkflowDefinitionRefused) {
        // expected
    }

    expect(app(TenantConnection::class)->get()->table('workflow_definitions')->count())->toBe(0)
        ->and(app(TenantConnection::class)->get()->table('workflow_steps')->count())->toBe(0);
});
