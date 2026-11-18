<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Access;

/**
 * The closed refusal vocabulary of the access domain (D-26).
 *
 * **One place, so a criterion can assert the cause rather than the status
 * class.** Fourteen reasons, and several of them are refused by sibling guards
 * with the same HTTP status: a test asserting "403" alone proves only that
 * *something* refused, which is the mask shape the plan's section 6.3 names as
 * the big one for this task.
 *
 * Three finer causes - `not_granted`, `explicit_deny`, `out_of_scope` - are
 * deliberately **not** members here. They never reach an HTTP body; they are
 * written to `access_log.detail.reason` and live on {@see AccessDecision} as
 * the detail. All three surface to a caller as {@see self::NotPermitted},
 * because telling a caller whether an explicit deny exists discloses something
 * and buys nothing (D-26).
 */
enum RefusalReason: string
{
    /**
     * The caller holds no allowing contribution for the key and scope asked
     * about, or holds a deny that covers it. The three finer causes collapse
     * here.
     */
    case NotPermitted = 'not_permitted';

    /** A branch-scoped key was asked about with no branch (D-8 (d)). */
    case BranchRequired = 'branch_required';

    /**
     * The key is in no module's declarations. A server defect, not a caller
     * error: fail closed and log at error level (D-26).
     */
    case UnknownPermission = 'unknown_permission';

    /** The token's `perm_version` is behind the employee's row (D-7). */
    case PermissionsChanged = 'permissions_changed';

    /** The employee row is missing, soft-deleted, not acting, or not linked to the user (D-6). */
    case EmployeeUnavailable = 'employee_unavailable';

    /** The change would leave the tenant with no acting owner. */
    case LastOwner = 'last_owner';

    /** An actor may not make this change to their own access. */
    case SelfChange = 'self_change';

    /** An actor may not grant rights they do not hold themselves. */
    case NoEscalation = 'no_escalation';

    /** A role still assigned to an employee may not be deleted. */
    case RoleInUse = 'role_in_use';

    /** A system role (`owner`) may not be renamed, re-permissioned or deleted. */
    case SystemRole = 'system_role';

    /** A per-person row for a non-branch-scoped key named a branch (D-8 (e)). */
    case ScopeNotApplicable = 'scope_not_applicable';

    /** A grant named a branch the employee is not attached to (D-11). */
    case NotAttached = 'not_attached';

    /** A branch may not be detached while scoped grants for it remain (D-11). */
    case HasScopedGrants = 'has_scoped_grants';

    /** The request itself is malformed, including a disallowed field (D-32). */
    case Validation = 'validation';

    /**
     * The operation grants, removes or changes access and named no actor
     * (D-36).
     *
     * A **question-level** refusal in D-33's sense, the same species as
     * {@see self::BranchRequired}: a required input of the command is
     * missing, so it is answered before any rights are read. Deliberately
     * not {@see self::NoEscalation}, which is a proposition about rights -
     * one code carrying both would mean a test asserting `no_escalation`
     * could not tell AC-36's real subject from a defect at a call site.
     *
     * No end user can produce it: over HTTP the actor comes from the token.
     * It fails closed and is logged at error level, like
     * {@see self::UnknownPermission}.
     */
    case ActorRequired = 'actor_required';

    /**
     * A branch id names no branch (T-014 AC-52, D-6).
     *
     * Rendered as `validation` with this value as the `reason`, so the wire
     * keeps one status for a submission the caller can correct and still says
     * which part was wrong.
     */
    case UnknownBranch = 'unknown_branch';

    /**
     * The branch exists and is deactivated, so nothing new may be scoped to it
     * (T-014 AC-15, AC-52). Detaching, removing and clearing are never
     * refused with it (D-6).
     */
    case BranchInactive = 'branch_inactive';

    /**
     * The employee is still the head of a live department or the manager of a
     * live profile, so they may not be soft-deleted (T-038 AC-85, D-43).
     * Rendered as 403 with the reason alone; the kinds of dependent travel
     * only on the in-process refusal.
     */
    case HasDependents = 'has_dependents';
}
