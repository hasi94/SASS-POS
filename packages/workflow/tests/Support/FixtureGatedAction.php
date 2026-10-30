<?php

declare(strict_types=1);

namespace Flexipos\Workflow\Tests\Support;

use Flexipos\Contracts\Workflow\WorkflowContext;
use Flexipos\Contracts\Workflow\WorkflowEntityType;
use Flexipos\Contracts\Workflow\WorkflowGate;

/**
 * A caller that runs its gated action only when `evaluate()` says no
 * approval is required (T-020 Ruling 3).
 *
 * **It shows the fail-closed pattern a real caller must follow.** It
 * never catches `WorkflowEvaluationFailed` - the counter stays untouched
 * on a throw, so a test can assert the action never ran.
 */
final class FixtureGatedAction
{
    public int $ranCount = 0;

    public function __construct(private readonly WorkflowGate $gate) {}

    public function run(WorkflowEntityType $entityType, WorkflowContext $context): void
    {
        $decision = $this->gate->evaluate($entityType, $context);

        if (! $decision->requiresApproval()) {
            $this->ranCount++;
        }
    }
}
