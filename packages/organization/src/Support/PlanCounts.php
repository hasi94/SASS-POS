<?php

declare(strict_types=1);

namespace Flexipos\Organization\Support;

use Illuminate\Database\Connection;

/**
 * The live counts a plan-limit check reads (T-016 Ruling 7).
 *
 * Every count runs on the tenant connection, inside the caller's own
 * transaction, and locks the rows it counts (`lockForUpdate()`) - a no-op on
 * SQLite, and the race it would close on the real engine is risk 3 of the
 * T-016 plan, not a criterion.
 */
final class PlanCounts
{
    public static function activeBranches(Connection $connection): int
    {
        return $connection->table('branches')
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->lockForUpdate()
            ->count();
    }

    /**
     * Every active, non-deleted location - every type, including the
     * `branch_store` a branch's own creation adds (D-20).
     */
    public static function activeLocations(Connection $connection): int
    {
        return $connection->table('locations')
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->lockForUpdate()
            ->count();
    }

    /**
     * Terminals that hold a seat: not soft-deleted and not `revoked`. A
     * paused (`inactive`) terminal still holds its seat, because it can
     * resume without a check.
     */
    public static function seatedTerminals(Connection $connection): int
    {
        return $connection->table('terminals')
            ->whereNull('deleted_at')
            ->where('status', '!=', TerminalStatus::REVOKED)
            ->lockForUpdate()
            ->count();
    }
}
