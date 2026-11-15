<?php

declare(strict_types=1);

namespace Flexipos\Sales\Approvals;

/**
 * `approval_requests.status`. `expired` is never written by anything this task
 * ships; it is a column value a later job may set, and a row in it is refused
 * like any other that is not `pending` or `approved`.
 */
enum ApprovalStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Expired = 'expired';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
