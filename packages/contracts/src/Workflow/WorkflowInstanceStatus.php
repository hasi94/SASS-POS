<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Workflow;

/**
 * `workflow_instances.status` (`docs/database.md` section 6.15, T-020
 * AC-11, AC-19 to AC-21). `expired` is shipped but never written in this
 * task (D-9, Ruling 7): no scheduled job marks anything expired.
 */
enum WorkflowInstanceStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
}
