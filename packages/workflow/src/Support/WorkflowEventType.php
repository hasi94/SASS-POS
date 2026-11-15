<?php

declare(strict_types=1);

namespace Flexipos\Workflow\Support;

/**
 * The full `workflow_events.event` vocabulary (`docs/database.md` section
 * 6.15, T-020 AC-18).
 *
 * This task writes {@see self::WRITTEN}; `delegated`, `escalated`,
 * `commented` and `expired` are shipped and valid but deliberately
 * unwritten (D-9, Ruling 8).
 */
enum WorkflowEventType: string
{
    case Started = 'started';
    case Assigned = 'assigned';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Delegated = 'delegated';
    case Escalated = 'escalated';
    case Commented = 'commented';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case Completed = 'completed';

    /**
     * @var list<self>
     */
    public const WRITTEN = [self::Started, self::Assigned, self::Approved, self::Rejected, self::Completed, self::Cancelled];
}
