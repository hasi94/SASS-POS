<?php

declare(strict_types=1);

namespace Flexipos\Auth\Tenancy;

use Flexipos\Auth\Exceptions\EmployeeUnavailable;
use Flexipos\Contracts\Access\AccessRefusal;
use Flexipos\Contracts\Access\EmployeeClaims;
use Flexipos\Contracts\Access\EmployeeClaimSource;
use Flexipos\Contracts\Access\RefusalReason;
use Flexipos\Kernel\Tenancy\Tenancy;

/**
 * Enter a shop, ask it for an employee's claims, leave - in one place
 * (T-010 AC-56, AC-59, AC-61, AC-62).
 *
 * **Two callers, one home, and the duplication it prevents is not
 * cosmetic.** Selection and tenant refresh both have to open the tenant
 * database, ask the published contract, translate its refusal into this
 * package's HTTP-facing one, and leave the tenant whatever happens. Written
 * twice, the two could come to disagree about the thing that matters most:
 * *when* the tenant is left. A missed `leave()` on one of the two paths
 * hands the next request in a long-running worker a tenant nobody asked
 * for, which is the single worst failure this platform can have. One
 * `finally` serves both.
 *
 * **The serving gate is `Tenancy::enter()`'s and is not caught here.** A
 * mapping that is not active, or a database below the schema floor, raises
 * the kernel's refusal and it travels to the renderer unchanged. T-009's
 * `RequireTenantToken` made the same choice in the same words: a tenant
 * that cannot be served is a deployment problem, and swallowing it into a
 * 401 or a 403 would make it look like a credential one. This class holds
 * no copy of the mapping status list and no reference to the types that
 * hold it, which is what keeps T-009 AC-47's instrument intact.
 *
 * **It translates exactly one reason and rethrows the rest.** The contract
 * promises `employee_unavailable`; any other refusal from the access
 * package is a defect there rather than an answer about this caller, and
 * swallowing it would turn a server fault into a 403 the operator never
 * sees.
 */
final class TenantEmployeeClaims
{
    public function __construct(
        private readonly EmployeeClaimSource $claims,
        private readonly Tenancy $tenancy,
    ) {}

    /**
     * The five claims for a membership, or null when it names no employee.
     *
     * A null `employee_id` opens **no** tenant database at all (AC-58):
     * there is no `enter()`, no mapping read, and the placeholder stanza is
     * untouched, which is T-009 AC-39's last sentence still holding for
     * exactly that case.
     *
     * @throws EmployeeUnavailable for any of D-6's four causes (AC-61)
     */
    public function forMembership(string $tenantId, ?string $employeeId, string $userId): ?EmployeeClaims
    {
        if ($employeeId === null) {
            return null;
        }

        $this->tenancy->enter($tenantId);

        try {
            return $this->claims->claimsFor($employeeId, $userId);
        } catch (AccessRefusal $refusal) {
            if ($refusal->reason() !== RefusalReason::EmployeeUnavailable) {
                throw $refusal;
            }

            throw EmployeeUnavailable::refused();
        } finally {
            $this->tenancy->leave();
        }
    }
}
