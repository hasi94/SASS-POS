<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Workflow;

/**
 * What `WorkflowApprovals::decide()` records against a task (T-020 AC-17).
 */
enum WorkflowVerdict: string
{
    case Approve = 'approve';
    case Reject = 'reject';
}
