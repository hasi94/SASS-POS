<?php

declare(strict_types=1);

namespace Flexipos\Access\Support;

use Illuminate\Database\Connection;

/**
 * Which `employees.status` values hold a plan-limit seat (T-016 Ruling 7).
 *
 * **Not the same set as `EmployeeStatus::ACTING`.** A suspended or on-leave
 * employee cannot act today, but either can come back without anybody
 * checking a limit, so both still hold a seat. Only `resigned` and
 * `terminated` are final enough to free one.
 */
final class EmployeeSeats
{
    /** @var list<string> */
    public const NON_SEAT = [EmployeeStatus::RESIGNED, EmployeeStatus::TERMINATED];

    public static function holdsSeat(string $status): bool
    {
        return ! in_array($status, self::NON_SEAT, true);
    }

    public static function count(Connection $connection): int
    {
        return $connection->table('employees')
            ->whereNull('deleted_at')
            ->whereNotIn('status', self::NON_SEAT)
            ->lockForUpdate()
            ->count();
    }
}
