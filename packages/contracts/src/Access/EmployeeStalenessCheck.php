<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Access;

/**
 * What `RequireTenantToken` asks on every request carrying an `employee`
 * claim (D-7).
 *
 * **One comparison, not two.** The employee's *status* is deliberately not a
 * second check here: a status change crossing the acting boundary bumps
 * `perm_version` (AC-27), so the version comparison already covers it and a
 * separate status read would be a second source for one fact (FP-184).
 *
 * **Absence is staleness, not a third answer.** A missing or soft-deleted
 * employee row returns true, because the credential names an employee the
 * tenant database no longer serves and the cure is the same: refresh, which
 * then fails with `employee_unavailable`.
 */
interface EmployeeStalenessCheck
{
    /**
     * Is the `perm_version` this token carries behind the employee's row?
     *
     * The middleware turns true into 401 `permissions_changed` with no
     * handler and **no** `access_log` row: a stale credential is not a denial
     * (D-7).
     */
    public function isStale(string $employeeId, int $claimedPermVersion): bool;
}
