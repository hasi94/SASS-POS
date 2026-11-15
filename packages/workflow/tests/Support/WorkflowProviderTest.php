<?php

use Flexipos\Contracts\Workflow\WorkflowApprovals;
use Flexipos\Contracts\Workflow\WorkflowGate;
use Flexipos\Workflow\Engine\WorkflowEngine;
use Tests\TestCase;

uses(TestCase::class);

it('loads the workflow provider and binds both contracts to the one engine', function () {
    expect(app(WorkflowGate::class))->toBeInstanceOf(WorkflowEngine::class)
        ->and(app(WorkflowApprovals::class))->toBeInstanceOf(WorkflowEngine::class)
        ->and(app(WorkflowGate::class))->toBe(app(WorkflowApprovals::class));
});
