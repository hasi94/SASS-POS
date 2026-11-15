<?php

declare(strict_types=1);

namespace Flexipos\Workflow\Definitions;

/**
 * `workflow_steps.quorum` (T-020 AC-10).
 */
enum Quorum: string
{
    case Any = 'any';
    case All = 'all';
    case Majority = 'majority';
}
