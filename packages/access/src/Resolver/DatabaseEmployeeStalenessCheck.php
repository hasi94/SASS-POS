<?php

declare(strict_types=1);

namespace Flexipos\Access\Resolver;

use Flexipos\Access\Support\TenantConnection;
use Flexipos\Contracts\Access\EmployeeStalenessCheck;

/**
 * D-7's per-request question, answered from the tenant database (AC-66).
 *
 * **One read of one column, and deliberately not a second guard.** The
 * employee's *status* is not consulted here: a status change crossing the
 * acting boundary bumps `perm_version` (AC-27), so the version comparison
 * already covers it, and a status read beside it would be a second source
 * for one fact (FP-184). What this class knows is one number.
 *
 * **Absence is staleness, and that is the third state being refused rather
 * than folded away.** A missing or soft-deleted employee row answers true,
 * because the credential names an employee the tenant database no longer
 * serves. Folding "no row" into "not stale" would make a deleted employee's
 * token outlive the deletion until `exp`, which is exactly the window D-7
 * closes. The cure a caller is told about is the same either way: refresh,
 * which then refuses `employee_unavailable` (AC-62).
 *
 * **The test is inequality, not "behind".** The interface's question reads
 * naturally as *is the claim behind the row*, and for every state this
 * system can produce the two are the same: `perm_version` only ever rises,
 * through the single atomic statement in {@see
 * \Flexipos\Access\Support\PermVersion}. A claim *ahead* of the row is a
 * tenant database that moved backwards - a restore, a replica served by
 * mistake - and serving a token against it would mean trusting the token
 * over the database, which D-4 settles the other way: the database is the
 * authority and the claim is a hint. So any difference refuses.
 *
 * **It is read-only and writes nothing**, including no `access_log` row: a
 * stale credential is not a denial (D-7, AC-66), and the middleware that
 * calls this is where that absence is asserted.
 */
final class DatabaseEmployeeStalenessCheck implements EmployeeStalenessCheck
{
    public function __construct(private readonly TenantConnection $tenant) {}

    public function isStale(string $employeeId, int $claimedPermVersion): bool
    {
        $row = $this->tenant->get()->table('employees')
            ->where('id', $employeeId)
            ->whereNull('deleted_at')
            ->first(['perm_version']);

        if ($row === null) {
            return true;
        }

        /** @var object{perm_version: int|string} $row */
        return (int) $row->perm_version !== $claimedPermVersion;
    }
}
