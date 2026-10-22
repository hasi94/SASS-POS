<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Organization;

/**
 * What a terminal request asks the tenant database on every call, and what a
 * terminal refresh asks before it consumes anything (T-014 AC-34, AC-36).
 *
 * **The tenant database is the authority, on every request** (D-19). A
 * terminal token is a 15-minute bearer credential; "revoked" has to mean now,
 * so the verdict is read from the terminal row and its credential row each
 * time and never from a claim.
 *
 * Implemented by `packages/organization` over the tenant connection that the
 * caller has already entered. Callers are the host's `RequireTerminalToken`
 * middleware and `packages/auth`'s terminal refresh, which reach it through
 * this contract only.
 */
interface TerminalAuthority
{
    /**
     * The terminal's branch id, only while the terminal may act.
     *
     * Non-null exactly when the terminal row exists, is not soft-deleted, its
     * `status` is `active` and its credential row's `status` is `active`.
     * Every other state - missing, `pending`, `inactive`, `revoked`, a
     * revoked or absent credential - is null, and the caller must not learn
     * which.
     */
    public function activeBranchOf(string $terminalId): ?string;

    /**
     * Is the terminal administratively paused (D-35)?
     *
     * True only for a live row whose `status` is `inactive` and whose
     * credential is not revoked. A paused till refreshes every 15 minutes, so
     * refresh needs to tell "paused" (refuse, keep the family) from "revoked"
     * (refuse, revoke the family); `activeBranchOf()` alone cannot.
     */
    public function isPaused(string $terminalId): bool;

    /**
     * Stamp `last_seen_at` on the terminal and on its current device.
     */
    public function recordSeen(string $terminalId): void;
}
