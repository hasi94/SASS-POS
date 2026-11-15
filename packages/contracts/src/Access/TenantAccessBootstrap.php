<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Access;

/**
 * What `packages/platform`'s provisioning asks `packages/access` to do for
 * the very first employee and role of a brand-new tenant (T-026 D-23, D-24,
 * Ruling 5, Ruling 7).
 *
 * **Why this exists at all.** Granting the system `owner` role to the first
 * employee cannot go through the ordinary `AssignRole` handler:
 * `Escalation::requireRoleHeld()` demands the actor already hold every key
 * the role confers, which is unsatisfiable for the first grant in a tenant
 * that holds nothing yet, and a `null` actor is refused outright. This
 * contract is the one, narrowly-gated door around that guard - gated on a
 * precondition ("no live owner yet"), checked inside {@see self::grantOwner()}
 * itself, never on who is calling.
 *
 * **Published here, reached only through this interface.** `packages/platform`
 * may not import anything under `Flexipos\Access\*`; every method below is
 * the complete surface it is allowed to drive.
 */
interface TenantAccessBootstrap
{
    /**
     * Reconcile the tenant's `permissions` table and the system `owner`
     * role with what the booted application declares.
     *
     * @return list<string> the keys the access package's own sync result
     *                      reports `refused` - a declared key whose
     *                      `is_branch_scoped` flag conflicts with an
     *                      already-stored row
     */
    public function seedPermissions(): array;

    /**
     * Find the tenant's owner employee by its fixed code (`OWNER`, D-24), or
     * create it.
     *
     * A second call for an already-seeded tenant returns the same id: the
     * code is the resume key, and this method never inserts a second row for
     * it.
     *
     * @return string the owner employee's id
     */
    public function ensureOwnerEmployee(OwnerSeed $seed): string;

    /**
     * Attach the owner employee to the branch `seed_core` created, as their
     * default (AC-29).
     *
     * A second call for the same pair is a no-op: this method checks
     * `employee_branches` itself before calling the unchanged `AttachBranch`
     * handler, which otherwise refuses a re-attach (architect's ruling to
     * `dev`).
     */
    public function attachOwnerBranch(string $employeeId, string $branchId): void;

    /**
     * The D-23 operation: grant the system `owner` role to this employee,
     * inside one tenant transaction.
     *
     * It never calls `Escalation`. Its precondition - no **other** live
     * employee in this tenant already holds `owner` - is its guard, checked
     * inside the same transaction as the write. A second call for the same
     * employee who already holds it is a no-op.
     *
     * @throws TenantBootstrapRefused when another live employee already
     *                                holds `owner`, or when no system role
     *                                keyed `owner` exists yet
     */
    public function grantOwner(string $employeeId): void;

    /**
     * Link the tenant employee to the master `users` row that is their login
     * (idempotent).
     */
    public function linkUser(string $employeeId, string $userId): void;

    /**
     * Declared keys the system `owner` role does not (yet) hold, re-read
     * from the database rather than trusted from a prior report (D-17,
     * `verify`'s own convention).
     *
     * @return list<string>
     */
    public function ownerMissingKeys(): array;

    /**
     * The owner employee's current state, for `verify`, or null when no
     * `OWNER`-coded employee exists yet.
     *
     * @return array{employee_id: string, user_id: ?string, branch_ids: list<string>, holds_owner: bool}|null
     */
    public function ownerState(): ?array;
}
