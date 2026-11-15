<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Access;

/**
 * What `packages/auth` calls when it mints a tenant token (D-2, D-6).
 *
 * **This interface is the whole reason authentication gains no new boundary
 * edge.** `deptrac.yaml` already grants `Package_Auth -> Package_Contracts`;
 * selection and tenant refresh reach the employee-derived claims through this
 * contract, so `Package_Access` never appears in authentication's ruleset and
 * the two packages stay on opposite sides of the master/tenant line the plan's
 * section 2 keeps them on.
 *
 * The implementation lives in `packages/access` and reads the tenant database.
 */
interface EmployeeClaimSource
{
    /**
     * The five claims for a membership whose `tenant_users.employee_id` is set.
     *
     * Implementations apply D-6's four mint-time checks - the row exists, is
     * not soft-deleted, has an acting status, and its `user_id` equals the
     * token's user - and refuse rather than mint a token with empty rights,
     * because failing closed makes a wrong `tenant_users.employee_id`
     * observable instead of handing one person another's permissions.
     *
     * A refusal is thrown carrying {@see RefusalReason::EmployeeUnavailable}.
     * The reason is the published part; the exception class is the
     * implementing package's, so a caller matches on the reason rather than
     * on a type it would have to import across the boundary.
     */
    public function claimsFor(string $employeeId, string $userId): EmployeeClaims;
}
