<?php

declare(strict_types=1);

namespace Flexipos\Access\Support;

use Flexipos\Access\Exceptions\AccessRefused;
use Flexipos\Access\Resolver\DatabasePermissionResolver;
use Illuminate\Database\Connection;

/**
 * Nobody hands out what they do not hold (AC-36).
 *
 * **"Holds it at that scope" is asked of the resolver, never recomputed.**
 * Three operations consult this class - assigning a role, adding a key to a
 * custom role, and writing a per-person **allow** - and if any of them
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
 * **An unknown actor is refused, and that is a decision rather than an
 * oversight.** `actorId` is null when an operation did not come through the
 * HTTP surface, and the tempting reading is "no actor, no escalation, carry
 * on" - which is a guard that permits whenever its input is missing, the
 * precise shape this project forbids. Absence is a third state: there is no
 * actor whose rights bound this grant, so the grant is unbounded, so it is
 * refused. Nothing shipped grants without an actor; the day a background
 * process needs to, it gets an actor or an explicit decision, not silence.
 *
 * **A deny needs no check** (AC-36, in terms). Taking a right away from
 * somebody cannot raise anyone above the actor, and requiring the actor to
 * hold a key before they can deny it would stop an administrator revoking
 * a permission they themselves were never given.
 */
final class Escalation
{
    public function __construct(private readonly DatabasePermissionResolver $resolver) {}

    /**
     * Refuse unless the actor holds this key at this scope.
     *
     * @throws AccessRefused no_escalation
     */
    public function requireHeld(?string $actorId, string $permissionKey, ?string $branchId): void
    {
        if ($actorId === null) {
            throw AccessRefused::noEscalation(
                "Granting [{$permissionKey}] needs a known actor whose own rights bound the grant."
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
     * @throws AccessRefused no_escalation
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
