<?php

use Flexipos\Contracts\Workflow\WorkflowDecision;
use Flexipos\Contracts\Workflow\WorkflowOutcome;
use Tests\TestCase;

uses(TestCase::class);

it('reports requiresApproval() both ways', function () {
    expect((new WorkflowDecision(WorkflowOutcome::Pending, 'inst', 'key'))->requiresApproval())->toBeTrue()
        ->and((new WorkflowDecision(WorkflowOutcome::NotRequired))->requiresApproval())->toBeFalse();
});
