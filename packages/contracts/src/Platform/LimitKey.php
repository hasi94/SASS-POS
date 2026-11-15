<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Platform;

/**
 * The five `plan_limits.limit_key` values this task hard-enforces (T-016 D-4).
 *
 * Deliberately absent, by the same decision: `products` (no catalog table
 * exists yet), and `monthly_sales`, `storage_mb`, `api_calls_per_day` (continuous
 * metrics with no realtime metering path built). A `plan_limits` row for any of
 * those keys is read by nothing in this task and enforces nothing (AC-7) - this
 * enum's closed set is the structural proof.
 */
enum LimitKey: string
{
    case Branches = 'branches';
    case Locations = 'locations';
    case Terminals = 'terminals';
    case Employees = 'employees';
    case Users = 'users';
}
