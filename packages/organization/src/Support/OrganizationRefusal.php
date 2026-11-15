<?php

declare(strict_types=1);

namespace Flexipos\Organization\Support;

/**
 * The closed refusal vocabulary of the organization package (T-014 Ruling 8,
 * AC-63).
 *
 * **One place, so a criterion can assert the cause and not the status
 * class.** Eleven of these share the 403, and a test asserting "403" alone
 * proves only that something refused. The set is asserted in both directions,
 * and the host renders every member through one class, so a reason added
 * without a decision, or one rendered with another status, fails a test.
 *
 * Statuses, which the host owns and this enum states so the two cannot drift:
 * `validation`, `unknown_branch` and `unknown_terminal` are 422 (the last two
 * as `validation` with a `reason`), `not_found` is 404, everything else is 403.
 */
enum OrganizationRefusal: string
{
    /** The submission is malformed, incomplete or names a field that may not change. */
    case Validation = 'validation';

    /** No such row in this tenant, or a well-formed id of another tenant's. */
    case NotFound = 'not_found';

    /** A branch id names no branch (rendered as `validation` with this reason). */
    case UnknownBranch = 'unknown_branch';

    /** A terminal id names no terminal (rendered as `validation` with this reason). */
    case UnknownTerminal = 'unknown_terminal';

    /** The branch exists and is switched off. */
    case BranchInactive = 'branch_inactive';

    /** A branch with a `pending` or `active` terminal may not be deactivated. */
    case HasActiveTerminals = 'has_active_terminals';

    /** The only active branch may not be deactivated. */
    case LastBranch = 'last_branch';

    /** A location that is a default store or a terminal's selling place may not be deactivated. */
    case LocationInUse = 'location_in_use';

    /** Only a `pending` terminal can be given a new activation code. */
    case TerminalNotPending = 'terminal_not_pending';

    /** Only an `active` terminal can be paused. */
    case TerminalNotActive = 'terminal_not_active';

    /** A `revoked` terminal is final. */
    case TerminalRevoked = 'terminal_revoked';

    /** The employee layer of the settings hierarchy cannot be written. */
    case ScopeNotSupported = 'scope_not_supported';

    /** An operation that bounds itself by the actor was invoked with none. */
    case ActorRequired = 'actor_required';

    /**
     * The HTTP status this refusal is answered with (AC-63).
     */
    public function status(): int
    {
        return match ($this) {
            self::Validation, self::UnknownBranch, self::UnknownTerminal => 422,
            self::NotFound => 404,
            default => 403,
        };
    }
}
