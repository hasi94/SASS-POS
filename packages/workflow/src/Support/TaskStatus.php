<?php

declare(strict_types=1);

namespace Flexipos\Workflow\Support;

/**
 * `workflow_tasks.status` (T-020 Ruling 7, Ruling 8).
 */
enum TaskStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Skipped = 'skipped';
}
