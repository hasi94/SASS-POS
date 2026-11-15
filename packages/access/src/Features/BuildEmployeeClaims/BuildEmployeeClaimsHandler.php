<?php

declare(strict_types=1);

namespace Flexipos\Access\Features\BuildEmployeeClaims;

use Flexipos\Access\Exceptions\AccessRefused;
use Flexipos\Access\Support\EmployeeBranches;
use Flexipos\Access\Support\EmployeeStatus;
use Flexipos\Access\Support\PermHash;
use Flexipos\Access\Support\ScopeKey;
use Flexipos\Access\Support\TenantConnection;
use Flexipos\Contracts\Access\EmployeeClaims;
use Flexipos\Contracts\Access\EmployeeClaimSource;
use Psr\Log\LoggerInterface;

/**
 * D-6's four checks and D-2's five claims, read from the tenant database
 * (AC-56, AC-57, AC-58, AC-61).
 *
 * **It fails closed, and that is the whole of D-6.** A membership whose
 * `tenant_users.employee_id` names a row that is absent, soft-deleted, not
 * acting, or linked to a different user is refused - no token at all -
 * rather than minted with empty rights. The alternative looks safer and is
 * not: a token with no contributions is a working credential for every
 * `authenticated_only` route, and a wrong `employee_id` would be invisible
 * instead of loud.
 *
 * **One reason for four causes, and the cause in the log.** AC-61 requires
 * one body, because telling a caller *which* of the four applied discloses
 * the state of a shop's staff to somebody who has not proved they may know
 * it. The operator needs the opposite, so the cause is logged at warning
 * level with no personal data - ids, never names, never the email.
 *
 * **`all_branches` and `branch_ids` are different facts and neither is
 * derived from the other** (D-2, AC-57). `branch_ids` is the explicit
 * attachment list and is never expanded to "every branch", because there is
 * no `branches` table to expand from and defining the empty list as "all"
 * would redefine the claim the day FP-209 lands. `all_branches` is true
 * exactly when the employee holds an **allowing contribution at the null
 * scope** - the hash's own notion of a contribution, read through
 * {@see PermHash::contributionsFor()} so the two cannot fork. A null-scope
 * *deny* alone leaves it false, which is why the flag is computed from
 * `allowed` and not from the scope alone.
 *
 * **It reads, and writes nothing.** No `perm_version` is bumped here and no
 * `access_log` row is written: minting a token is not an administrative
 * operation and not a denial.
 */
final class BuildEmployeeClaimsHandler implements EmployeeClaimSource
{
    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly EmployeeStatus $statuses,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * {@inheritDoc}
     *
     * @throws AccessRefused carrying `employee_unavailable` for any of D-6's four causes
     */
    public function claimsFor(string $employeeId, string $userId): EmployeeClaims
    {
        return $this->handle(new BuildEmployeeClaims($employeeId, $userId));
    }

    /**
     * @throws AccessRefused carrying `employee_unavailable` for any of D-6's four causes
     */
    public function handle(BuildEmployeeClaims $command): EmployeeClaims
    {
        $connection = $this->tenant->get();

        $employee = $connection->table('employees')
            ->where('id', $command->employeeId)
            ->whereNull('deleted_at')
            ->first(['id', 'user_id', 'status', 'perm_version']);

        if ($employee === null) {
            // Absent and soft-deleted are one cause here on purpose: the
            // query excludes deleted rows, so a caller cannot learn from
            // the refusal whether the employee ever existed.
            $this->refuse($command, 'missing_or_deleted');
        }

        /** @var object{id: string, user_id: ?string, status: string, perm_version: int|string} $employee */
        if (! $this->statuses->isActing((string) $employee->status)) {
            $this->refuse($command, 'not_acting');
        }

        if ($employee->user_id === null || (string) $employee->user_id !== $command->userId) {
            $this->refuse($command, 'user_mismatch');
        }

        $permVersion = (int) $employee->perm_version;
        $contributions = PermHash::contributionsFor($connection, $command->employeeId);

        return new EmployeeClaims(
            employee: $command->employeeId,
            branchIds: EmployeeBranches::of($connection, $command->employeeId),
            allBranches: ScopeKey::anyAllowsEveryBranch($contributions),
            permVersion: $permVersion,
            permHash: PermHash::compute($permVersion, $contributions),
        );
    }

    /**
     * Refuse, with the cause where the operator can read it.
     *
     * @throws AccessRefused
     */
    private function refuse(BuildEmployeeClaims $command, string $cause): never
    {
        $this->logger->warning('A tenant selection named an employee that cannot be served.', [
            'cause' => $cause,
            'employee_id' => $command->employeeId,
            'user_id' => $command->userId,
        ]);

        throw AccessRefused::employeeUnavailable(
            "The employee [{$command->employeeId}] cannot be served ({$cause})."
        );
    }
}
