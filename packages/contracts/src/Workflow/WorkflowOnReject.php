<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Workflow;

/**
 * `workflow_definitions.on_reject` (T-020 AC-19, D-7). Reported on the
 * instance read; this engine performs no business rollback and `stop` and
 * `return_to_requester` are otherwise indistinguishable in its own
 * behaviour.
 */
enum WorkflowOnReject: string
{
    case Stop = 'stop';
    case ReturnToRequester = 'return_to_requester';
}
