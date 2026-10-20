<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Access;

/**
 * The one question every later module asks about authorization.
 *
 * It is published here, in `packages/contracts`, rather than in
 * `packages/access`, so that a module can ask it without reaching into the
 * access tables - which `deptrac.yaml` forbids on purpose: `Module_Demo` is
 * granted `Package_Contracts` and deliberately **not** `Package_Access`
 * (AC-11).
 *
 * **The signature is final from the first day (D-1).** A resolver returning
 * "a set of keys" cannot later become "a set of keys per branch" without
 * changing every caller written in between, so the optional branch is part of
 * the question now, while branches themselves are still FP-209 and
 * `$branchId` is an opaque identifier with no table behind it.
 */
interface PermissionResolver
{
    /**
     * May this employee do this, at this branch?
     *
     * `$branchId` is null for "not about a branch". That is not the same as
     * "about every branch": a key declared branch-scoped and asked with no
     * branch is refused {@see RefusalReason::BranchRequired}, because
     * answering it would mean picking a branch on the caller's behalf
     * (D-8 (d)).
     *
     * Never throws for an unknown key or an unavailable employee: both are
     * refusals with their own reason, so a caller handles one shape.
     */
    public function decide(string $employeeId, string $permissionKey, ?string $branchId): AccessDecision;
}
