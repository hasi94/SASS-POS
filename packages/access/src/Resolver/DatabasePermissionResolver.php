<?php

declare(strict_types=1);

namespace Flexipos\Access\Resolver;

use Flexipos\Access\Support\EmployeeStatus;
use Flexipos\Access\Support\TenantConnection;
use Flexipos\Contracts\Access\AccessDecision;
use Flexipos\Contracts\Access\PermissionResolver;
use Flexipos\Contracts\Access\RefusalReason;
use Illuminate\Database\Connection;
use Psr\Log\LoggerInterface;

/**
 * D-8 (a) to (f), answered from the tenant database, in one class.
 *
 * **No cache and no per-request memo (Ruling 5).** `docs/patterns.md` §5
 * admits a read model on a measurement and there is none; the spec invents
 * no performance figure and neither does this class. The consequence is that
 * AC-45 (the answer is this tenant's) and AC-46 (the answer is current) are
 * satisfied **structurally**: there is no cache to mis-key and no memo to go
 * stale. Both criteria keep fixture doubles that show what a mis-keyed cache
 * would do, so they remain falsifiable rather than vacuous. If a later task
 * measures a problem, the answer is a decision with a stated staleness
 * bound, not a quiet memo added here.
 *
 * **The order of the four refusals is a decision and it is not arbitrary.**
 *
 * 1. `unknown_permission` first. It is a **server defect** - a typo in a
 *    guard - and D-26 says fail closed and log at error level. Put it after
 *    the employee check and whether the typo is ever reported would depend
 *    on who happened to ask: a guard on a route only non-acting employees
 *    reach would deny everyone forever and log nothing.
 * 2. `branch_required` second, for the same reason in a weaker form: a
 *    branch-scoped key asked with no branch is a question that cannot be
 *    answered at all, whoever is asking, and answering it would mean picking
 *    a branch on the caller's behalf (D-8 (d)).
 * 3. `employee_unavailable` third. AC-43's "every question is refused
 *    `employee_unavailable` regardless of grants" is about **grants**, and
 *    this ordering honours that: no contribution of any kind is read for an
 *    employee who cannot act.
 * 4. `not_permitted` last, with the finer cause attached.
 *
 * The first two are properties of the **question**, the third of the
 * **subject**, the fourth of the **data**. `dev` chose this ordering and
 * declared it; it is observable, so a different ruling is a one-line change
 * here plus the truth table's rows.
 *
 * **Covering, once, in one predicate.** D-8 (b) and (c) are the same
 * question asked of an allow and of a deny, and writing them twice is how a
 * deny comes to cover a scope an allow does not. {@see self::covers()} is
 * the only place either is decided.
 */
final class DatabasePermissionResolver implements PermissionResolver
{
    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly EmployeeStatus $statuses,
        private readonly LoggerInterface $logger,
    ) {}

    public function decide(string $employeeId, string $permissionKey, ?string $branchId): AccessDecision
    {
        // T-014 AC-61: two spellings of one branch id are one branch. Stored scopes
        // are upper-case, so the id asked about is folded before it is compared.
        // Only the case is folded here; a malformed id matches nothing and is
        // answered like any other scope that is not held.
        $branchId = $branchId === null ? null : strtoupper($branchId);

        $connection = $this->tenant->get();

        $permission = $connection->table('permissions')->where('key', $permissionKey)->first();

        if ($permission === null) {
            // D-26: a server defect, not a caller error. The operator sees
            // it; the caller sees a refusal either way.
            $this->logger->error('Access check named a permission key that is not registered.', [
                'permission_key' => $permissionKey,
                'employee_id' => $employeeId,
            ]);

            return AccessDecision::refuse(RefusalReason::UnknownPermission);
        }

        /** @var object{is_branch_scoped: int|bool} $permission */
        $isBranchScoped = (bool) $permission->is_branch_scoped;

        if ($isBranchScoped && $branchId === null) {
            return AccessDecision::refuse(RefusalReason::BranchRequired);
        }

        $employee = $connection->table('employees')
            ->where('id', $employeeId)
            ->whereNull('deleted_at')
            ->first();

        if ($employee === null) {
            return AccessDecision::refuse(RefusalReason::EmployeeUnavailable);
        }

        /** @var object{status: string} $employee */
        if (! $this->statuses->isActing((string) $employee->status)) {
            return AccessDecision::refuse(RefusalReason::EmployeeUnavailable);
        }

        // A per-person deny covering the asked scope wins over everything,
        // and there is no "a more specific allow beats a general deny"
        // (D-8 (a)). It is read first so that no allowing contribution can
        // short-circuit it.
        $personal = $this->personalScopes($connection, $employeeId, $permissionKey);

        foreach ($personal['deny'] as $scope) {
            if ($this->covers($scope, $isBranchScoped, $branchId)) {
                return AccessDecision::refuse(
                    RefusalReason::NotPermitted,
                    AccessDecision::DETAIL_EXPLICIT_DENY,
                );
            }
        }

        $allowing = array_merge(
            $personal['allow'],
            $this->roleScopes($connection, $employeeId, $permissionKey),
        );

        foreach ($allowing as $scope) {
            if ($this->covers($scope, $isBranchScoped, $branchId)) {
                return AccessDecision::allow();
            }
        }

        // The two shapes of "no" are different facts about the data and the
        // operator needs them apart: nobody ever granted this key, against
        // somebody holds it somewhere else. Both reach a caller as
        // `not_permitted` (D-26).
        return AccessDecision::refuse(
            RefusalReason::NotPermitted,
            $allowing === []
                ? AccessDecision::DETAIL_NOT_GRANTED
                : AccessDecision::DETAIL_OUT_OF_SCOPE,
        );
    }

    /**
     * Does this employee hold the key **at every branch at once**?
     *
     * The question AC-36 asks when a grant is being made at the null scope,
     * and the reason it cannot be asked through {@see self::decide()}: for
     * a branch-scoped key, `decide($id, $key, null)` refuses
     * `branch_required` before it reads a contribution, because picking a
     * branch on the caller's behalf is exactly what it must not do. "May
     * this person hand the key out for every branch?" is a different
     * question from "may this person use it here?", and the resolver has to
     * be able to answer both.
     *
     * **It is not on the published contract and must not be.** No caller
     * outside this package has any business asking it: the answer is not
     * an authorization decision, it is an input to one. What it reuses is
     * the part that must not fork - the two contribution readers and
     * {@see self::covers()} - evaluated as the non-branch-scoped case,
     * which is precisely "an allowing contribution with a null scope, and
     * no null-scope denial".
     */
    public function holdsEverywhere(string $employeeId, string $permissionKey): bool
    {
        $connection = $this->tenant->get();

        $employee = $connection->table('employees')
            ->where('id', $employeeId)
            ->whereNull('deleted_at')
            ->first();

        if ($employee === null) {
            return false;
        }

        /** @var object{status: string} $employee */
        if (! $this->statuses->isActing((string) $employee->status)) {
            return false;
        }

        $personal = $this->personalScopes($connection, $employeeId, $permissionKey);

        foreach ($personal['deny'] as $scope) {
            if ($this->covers($scope, false, null)) {
                return false;
            }
        }

        $allowing = array_merge(
            $personal['allow'],
            $this->roleScopes($connection, $employeeId, $permissionKey),
        );

        foreach ($allowing as $scope) {
            if ($this->covers($scope, false, null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Does a contribution at this scope cover the question being asked?
     *
     * D-8 (b): a scope covers a branch when it is null ("all branches") or
     * equal to it. D-8 (c): a key that is **not** branch-scoped can only be
     * held company-wide, so a contribution naming a branch confers nothing -
     * least privilege, and the reason a role assigned at one branch does not
     * hand out `employees.manage` for the whole shop. The branch asked about
     * is ignored for such a key, which is why it is not consulted in that
     * arm at all.
     */
    private function covers(?string $scope, bool $isBranchScoped, ?string $branchId): bool
    {
        if (! $isBranchScoped) {
            return $scope === null;
        }

        return $scope === null || $scope === $branchId;
    }

    /**
     * The scopes at which this employee is personally allowed and denied
     * this key.
     *
     * One read of the table, split here, because two reads could see two
     * different states of it.
     *
     * @return array{allow: list<?string>, deny: list<?string>}
     */
    private function personalScopes(Connection $connection, string $employeeId, string $permissionKey): array
    {
        $scopes = ['allow' => [], 'deny' => []];

        $rows = $connection->table('employee_permissions')
            ->where('employee_id', $employeeId)
            ->where('permission_key', $permissionKey)
            ->get(['branch_id', 'allowed']);

        foreach ($rows as $row) {
            /** @var object{branch_id: ?string, allowed: int|bool} $row */
            $scope = $row->branch_id === null ? null : (string) $row->branch_id;

            $scopes[(bool) $row->allowed ? 'allow' : 'deny'][] = $scope;
        }

        return $scopes;
    }

    /**
     * The scopes at which a role confers this key on this employee.
     *
     * A soft-deleted role contributes nothing: the assignment row may still
     * exist (AC-22 stops a role in use being deleted, but a fixture or a
     * repair can produce one), and a deleted role conferring rights would be
     * a grant nobody can see in the role list.
     *
     * @return list<?string>
     */
    private function roleScopes(Connection $connection, string $employeeId, string $permissionKey): array
    {
        $scopes = [];

        $rows = $connection->table('employee_roles')
            ->join('roles', 'roles.id', '=', 'employee_roles.role_id')
            ->join('role_permissions', 'role_permissions.role_id', '=', 'roles.id')
            ->where('employee_roles.employee_id', $employeeId)
            ->where('role_permissions.permission_key', $permissionKey)
            ->whereNull('roles.deleted_at')
            ->get(['employee_roles.branch_id as branch_id']);

        foreach ($rows as $row) {
            /** @var object{branch_id: ?string} $row */
            $scopes[] = $row->branch_id === null ? null : (string) $row->branch_id;
        }

        return $scopes;
    }
}
