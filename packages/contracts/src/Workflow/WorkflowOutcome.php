<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Workflow;

/**
 * What `evaluate()` decided (T-020 AC-13, AC-14).
 */
enum WorkflowOutcome
{
    case NotRequired;
    case Pending;
}
