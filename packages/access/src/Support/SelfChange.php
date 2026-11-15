<?php

declare(strict_types=1);

namespace Flexipos\Access\Support;

use Flexipos\Access\Exceptions\AccessRefused;

/**
 * Nobody changes their own access (AC-34).
 *
 * **Six operations, one rule, one file.** Assigning and removing a role,
 * setting and clearing a per-person permission, attaching and detaching a
 * branch are all "a change to somebody's access", and every one of them
 * refuses when the somebody is the actor. Six copies of
 * `if ($actorId === $employeeId)` is six chances for the seventh operation
 * to arrive without one - and the one that arrives without one is the hole,
 * not the six that have it.
 *
 * **What it is for.** An actor holding `access.assign` can already grant
 * themselves anything the escalation rule (AC-36) permits, so this is not a
 * privilege boundary; it is a **four-eyes** rule and a foot-gun guard. The
 * cases it actually stops are an administrator removing their own last role
 * and locking themselves out, and the audit trail reading "X granted X",
 * which is the one shape an audit log cannot corroborate.
 *
 * **Setting one's own PIN is not an access change** (AC-47) and this class
 * is deliberately not called from `SetPin`: a PIN is a credential. The rule
 * is about what a person may do, not about how they prove who they are.
 *
 * **An unknown actor is not a self-change.** `null` means the operation did
 * not come through the HTTP surface, so there is no actor to compare and
 * nothing here refuses. That is safe only because the dangerous half of an
 * absent actor - handing out rights nobody holds - is refused by
 * {@see Escalation}, where absence is treated as a third state rather than
 * folded into "no guard".
 */
final class SelfChange
{
    /**
     * Refuse when the actor is the subject.
     *
     * @throws AccessRefused self_change
     */
    public static function refuse(?string $actorId, string $employeeId, string $operation): void
    {
        if ($actorId !== null && $actorId === $employeeId) {
            throw AccessRefused::selfChange(
                "An employee may not {$operation} on their own record [{$employeeId}]."
            );
        }
    }
}
