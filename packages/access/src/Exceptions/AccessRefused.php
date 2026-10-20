<?php

declare(strict_types=1);

namespace Flexipos\Access\Exceptions;

use Flexipos\Access\Support\BranchScope;
use Flexipos\Access\Support\Escalation;
use Flexipos\Access\Support\LastOwner;
use Flexipos\Access\Support\RoleLookup;
use Flexipos\Access\Support\SelfChange;
use Flexipos\Contracts\Access\PermissionDeclaration;
use Flexipos\Contracts\Access\RefusalReason;
use RuntimeException;

/**
 * The one exception this package throws when it refuses to do something.
 *
 * **It is not constructible without a cause.** The constructor is private and
 * every way in is a named constructor carrying exactly one
 * {@see RefusalReason}, so there is no shape of this object that means
 * "refused" and nothing more - which is the shape a test asserting that
 * *something* refused cannot tell apart from a defect. Fourteen reasons and
 * several sibling guards share one HTTP status, so the cause has to travel
 * with the refusal rather than beside it.
 *
 * **The reason is the published part, not this class.** A caller across the
 * boundary matches on {@see self::$reason}, which lives in
 * `packages/contracts`; nothing outside this package should be catching the
 * type.
 *
 * Named constructors arrive with the operations that can produce them: a
 * reason with no thrower yet has no constructor here, so the list below is
 * always the set of refusals this package can actually produce.
 */
final class AccessRefused extends RuntimeException
{
    private function __construct(
        public readonly RefusalReason $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    /**
     * The request itself is malformed (D-26, D-32).
     *
     * Used by the registry for a declaration set that cannot be synced at
     * all: a key declared twice with different attributes. The malformed and
     * over-long cases never reach here, because
     * {@see PermissionDeclaration} refuses them at
     * construction, so such a key cannot exist as a value.
     */
    public static function validation(string $message): self
    {
        return new self(RefusalReason::Validation, $message);
    }

    /**
     * The role belongs to the platform, not to the shop (AC-21, D-12).
     *
     * Thrown only by {@see RoleLookup::requireCustom()},
     * which is the one place `roles.is_system` is read, so the three
     * operations AC-21 names cannot disagree about what a system role is.
     */
    public static function systemRole(string $message): self
    {
        return new self(RefusalReason::SystemRole, $message);
    }

    /**
     * The role is still assigned to somebody (AC-22).
     *
     * Distinct from {@see self::systemRole()} and from `validation` because
     * all three refuse a deletion and only this one is fixed by removing an
     * assignment: a caller told "refused" cannot tell which of the three it
     * met, and the remedy differs for each.
     */
    public static function roleInUse(string $message): self
    {
        return new self(RefusalReason::RoleInUse, $message);
    }

    /**
     * A grant named a branch the employee is not attached to (D-11, AC-31).
     *
     * Deliberately not `validation`: the request is well formed and the
     * remedy is a different operation - attach the branch (AC-35) - rather
     * than a corrected field. Thrown only by
     * {@see BranchScope::requireAttached()}, so the
     * four operations that take a scope cannot disagree about what
     * attachment means.
     */
    public static function notAttached(string $message): self
    {
        return new self(RefusalReason::NotAttached, $message);
    }

    /**
     * A branch may not be detached while scoped grants for it remain
     * (D-11, AC-35).
     *
     * The other direction of the same rule as {@see self::notAttached()}:
     * together they keep "every scoped grant names a branch the employee is
     * attached to" true at all times, which is what lets a token's
     * `branch_ids` be read as the complete list of scopes that can matter.
     */
    public static function hasScopedGrants(string $message): self
    {
        return new self(RefusalReason::HasScopedGrants, $message);
    }

    /**
     * A per-person row for a non-branch-scoped key named a branch
     * (D-8 (e), AC-32).
     *
     * Distinct from `not_attached`, and the difference is which half of the
     * request is wrong: here the key can only ever be held company-wide, so
     * attaching the branch would not help. A caller told `validation` would
     * go looking at the key's spelling.
     */
    public static function scopeNotApplicable(string $message): self
    {
        return new self(RefusalReason::ScopeNotApplicable, $message);
    }

    /**
     * An actor may not change their own access (AC-34, D-19).
     *
     * Thrown only by {@see SelfChange::refuse()}.
     * Setting one's own PIN is deliberately not one of these (AC-47): a PIN
     * is a credential, not a grant, and changing it alters nothing about
     * what the holder may do.
     */
    public static function selfChange(string $message): self
    {
        return new self(RefusalReason::SelfChange, $message);
    }

    /**
     * An actor may not hand out rights they do not hold (AC-36).
     *
     * Thrown only by {@see Escalation}, which is
     * also where the one definition of "holds it at that scope" lives: an
     * operation deciding that for itself would eventually decide it
     * differently from the resolver, and a grant the resolver then refuses
     * to honour is the gentler half of that mistake.
     */
    public static function noEscalation(string $message): self
    {
        return new self(RefusalReason::NoEscalation, $message);
    }

    /**
     * The change would leave the tenant with no acting owner (AC-37).
     *
     * Thrown only by {@see LastOwner::requireAnother()}.
     * Three operations can produce it - removing the owner assignment,
     * moving the owner out of the acting set, and soft-deleting them - and
     * they must agree about what an owner is, which is why none of them
     * counts for itself.
     */
    public static function lastOwner(string $message): self
    {
        return new self(RefusalReason::LastOwner, $message);
    }
}
