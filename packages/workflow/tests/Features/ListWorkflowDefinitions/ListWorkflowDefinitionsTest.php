<?php

use Flexipos\Access\Tests\Support\AccessTenantFixture;
use Flexipos\Workflow\Features\ListWorkflowDefinitions\ListWorkflowDefinitions;
use Flexipos\Workflow\Features\ListWorkflowDefinitions\ListWorkflowDefinitionsHandler;
use Flexipos\Workflow\Features\RegisterWorkflowDefinition\RegisterWorkflowDefinition;
use Flexipos\Workflow\Features\RegisterWorkflowDefinition\RegisterWorkflowDefinitionHandler;
use Flexipos\Workflow\Support\TenantConnection;
use Flexipos\Workflow\Tests\Fixtures\FixtureApproval;
use Illuminate\Support\Facades\Artisan;
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

it('lists an active row and an inactive row, but not a soft-deleted one (AC-23)', function () {
    $chain = workflowSeedChain();

    app(RegisterWorkflowDefinitionHandler::class)->handle(new RegisterWorkflowDefinition(
        json_encode(array_merge(json_decode(FixtureApproval::json($chain['roleOne'], $chain['roleTwo']), true), ['key' => 'wf-active'])),
    ));

    app(RegisterWorkflowDefinitionHandler::class)->handle(new RegisterWorkflowDefinition(
        json_encode(array_merge(json_decode(FixtureApproval::json($chain['roleOne'], $chain['roleTwo']), true), ['key' => 'wf-inactive', 'is_active' => false])),
    ));

    $tenant = app(TenantConnection::class)->get();

    $deletedId = app(RegisterWorkflowDefinitionHandler::class)->handle(new RegisterWorkflowDefinition(
        json_encode(array_merge(json_decode(FixtureApproval::json($chain['roleOne'], $chain['roleTwo']), true), ['key' => 'wf-deleted'])),
    ))->id;

    $tenant->table('workflow_definitions')->where('id', $deletedId)->update(['deleted_at' => now()->format('Y-m-d H:i:s.v')]);

    $rows = app(ListWorkflowDefinitionsHandler::class)
        ->handle(new ListWorkflowDefinitions);

    $keys = array_map(static fn (object $r): string => (string) $r->key, $rows);

    expect($keys)->toContain('wf-active')
        ->and($keys)->toContain('wf-inactive')
        ->and($keys)->not->toContain('wf-deleted');
});

it('exits 0 from the command', function () {
    workflowEnterTenant();
    expect(Artisan::call('workflow:definitions', ['--tenant' => 'tenant-a']))->toBe(0);
});

it('prints the name, a column AC-23 requires that the row-per-line format dropped (R-1)', function () {
    $chain = workflowSeedChain();

    app(RegisterWorkflowDefinitionHandler::class)->handle(new RegisterWorkflowDefinition(
        FixtureApproval::json($chain['roleOne'], $chain['roleTwo']),
    ));

    $exitCode = Artisan::call('workflow:definitions', ['--tenant' => 'tenant-a']);
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('Proof-of-pipeline fixture - not a business workflow')
        ->and($output)->toContain('fixture-approval');
});
