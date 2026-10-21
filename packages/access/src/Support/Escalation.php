<?php

declare(strict_types=1);

namespace Flexipos\Access\Support;

use Flexipos\Access\Exceptions\AccessRefused;
use Flexipos\Access\Resolver\DatabasePermissionResolver;
use Illuminate\Database\Connection;
use Psr\Log\LoggerInterface;

/**
 * Nobody hands out what they do not hold (AC-36).
 *
 * **"Holds it at that scope" is asked of the resolver, never recomputed.**
 * Five operations consult this class - assigning a role, adding a key to a
 * custom role, writing a per-person **allow**, clearing a per-person
 * **deny** (D-37) and moving an employee back into the acting set (D-38) -
 * and if any of them
 * worked out for itself what holding a key means, it would eventually
 * disagree with the thing that later decides whether the grant works. The
 * failure is quiet in the worse direction: the grant is written and the
 * resolver refuses to honour it, so the right was never real and nobody
 * was told.
 *
 * **A null scope is a stronger question than a branch scope, and it is a
 * different one.** Granting at one branch needs the actor allowed at that
 * branch, which is {@see DatabasePermissionResolver::decide()}. Granting
 * for every branch needs the actor to hold the key company-wide, which
 * `decide()` cannot express for a branch-scoped key - it refuses
 * `branch_required` first, by design - so the resolver answers it with
 * {@see DatabasePermissionResolver::holdsEverywhere()} out of the same
 * readers.
 *
 * **An unknown actor is refused `actor_required`, and the word is the
 * ruling rather than the behaviour** (D-36). `actorId` is null when an
 * operation did not come through the HTTP surface, and the tempting reading
 * is "no actor, no escalation, carry on" - which is a guard that permits
 * whenever its input is missing, the precise shape this project forbids.
 * Absence is a third state: there is no actor whose rights bound this
 * grant, so the grant is unbounded, so it is refused.
 *
 * It is a **question-level** refusal in D-33's sense - a required input of
 * the command is missing, the same species as `branch_required` - so it is
 * answered **before** the escalation check, which could not run without an
 * actor anyway. `no_escalation` is a proposition about rights and would
 * have told the one real caller that is coming, FP-206's provisioning of
 * the first owner (D-24), that provisioning *tried to escalate*; the
 * plausible response to a nonsense refusal is to bypass the guard.
 * Provisioning is expected to supply a **named system actor, not a null
 * one**, and this message says so.
 *
 * It is logged at **error** level, like `unknown_permission` and for the
 * same reason: no end user can produce it, because over HTTP the actor
 * comes from the verified token, so a row in the log is a defect at a call
 * site and not a caller's mistake.
 *
 * **Setting a deny needs no check** (AC-36, in terms). Taking a right away
 * from somebody cannot raise anyone above the actor, and requiring the
 * actor to hold a key before they can deny it would stop an administrator
 * revoking a permission they themselves were never given. **Clearing** a
 * deny is the opposite: it restores a right, so it is asked the question
 * an allow is asked (D-37, AC-33).
 */
final class Escalation
{
    public function __construct(
        private readonly DatabasePermissionResolver $resolver,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Refuse unless the actor holds this key at this scope.
     *
     * @throws AccessRefused actor_required when no actor was named,
     *                       no_escalation when the actor lacks the key
     */
    public function requireHeld(?string $actorId, string $permissionKey, ?string $branchId): void
    {
        if ($actorId === null) {
            $this->logger->error('A grant was attempted with no actor.', [
                'permission_key' => $permissionKey,
                'branch_id' => $branchId,
            ]);

            throw AccessRefused::actorRequired(
                "Granting [{$permissionKey}] needs a named actor whose own rights bound the grant."
            );
        }

        $held = $branchId === null
            ? $this->resolver->holdsEverywhere($actorId, $permissionKey)
            : $this->resolver->decide($actorId, $permissionKey, $branchId)->allowed;

        if (! $held) {
            $scope = $branchId ?? ScopeKey::ALL_BRANCHES;

            throw AccessRefused::noEscalation(
                "Actor [{$actorId}] does not hold [{$permissionKey}] at scope [{$scope}] and may not grant it."
            );
        }
    }

    /**
     * Refuse unless the actor holds every grant this employee holds, each at
     * its own scope (D-38, AC-27's entering crossing).
     *
     * Moving an employee back into the acting set restores everything they
     * hold, so it is a grant of all of it at once. **The grants are the
     * employee's contributions as {@see PermHash::contributionsFor()} reads
     * them** - the same list the hash, `all_branches` and "my permissions"
     * read, roles soft-deleted excluded - and never a third reading. Every
     * *allowing* contribution is checked at its scope, including one a
     * personal deny currently overrides: the spec asks for every grant's
     * key at the grant's scope, and checking one too many refuses a
     * reactivation the owner can still make, which is the safe failure.
     *
     * **A null actor is refused even for an employee who holds nothing.**
     * AC-27 says so in terms, unlike {@see self::requireRoleHeld()}'s empty
     * role: a status change is never actorless over HTTP, so a null here is
     * a call-site defect, not a case to wave through.
     *
     * @throws AccessRefused actor_required or no_escalation
     */
    public function requireEmployeeHeld(Connection $connection, ?string $actorId, string $employeeId): void
    {
        if ($actorId === null) {
            $this->logger->error('A reactivation was attempted with no actor.', [
                'employee_id' => $employeeId,
            ]);

            throw AccessRefused::actorRequired(
                "Restoring employee [{$employeeId}] to the acting set needs a named actor whose own rights bound it."
            );
        }

        $grants = [];

        foreach (PermHash::contributionsFor($connection, $employeeId) as [$allowed, $key, $scope]) {
            if ($allowed) {
                $grants[$key."\t".$scope] = [$key, $scope === ScopeKey::ALL_BRANCHES ? null : $scope];
            }
        }

        ksort($grants, SORT_STRING);

        foreach ($grants as [$key, $branchId]) {
            $this->requireHeld($actorId, $key, $branchId);
        }
    }

    /**
     * Refuse unless the actor holds every key a role confers, at the scope
     * the role is being granted at.
     *
     * The keys are read here rather than taken from the caller so that the
     * set checked is the set the assignment will actually confer: a caller
     * that read them a statement earlier would be checking the role as it
     * was, and the role's own permissions can be changed by the operation
     * next door.
     *
     * **A role holding no keys passes, including for an unknown actor**,
     * and that follows from the rule rather than escaping it: the
     * assignment confers nothing, so there is nothing the actor must hold.
     * The self-change rule still applies to it, and the day such a role
     * gains a key its holders gain nothing either - `SetRolePermissions`
     * checks the actor adding the key.
     *
     * @throws AccessRefused actor_required or no_escalation
     */
    public function requireRoleHeld(Connection $connection, ?string $actorId, string $roleId, ?string $branchId): void
    {
        $keys = $connection->table('role_permissions')
            ->where('role_id', $roleId)
            ->orderBy('permission_key')
            ->pluck('permission_key')
            ->map(static fn ($key): string => (string) $key)
            ->all();

        foreach ($keys as $key) {
            $this->requireHeld($actorId, $key, $branchId);
        }
    }
}
