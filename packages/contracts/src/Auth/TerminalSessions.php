<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Auth;

/**
 * The two master-database facts organization needs about a terminal's
 * sessions (T-014 AC-26, AC-38).
 *
 * Organization works inside one tenant database and may not read the master
 * (T-010 AC-11's stated limit); auth owns `tenants` and `refresh_tokens`.
 * This contract is the only way across.
 */
interface TerminalSessions
{
    /**
     * The tenant part an activation code for this tenant carries: its
     * `tenants.code`, verbatim (Ruling 7).
     *
     * @throws \RuntimeException when no live tenant has this id
     */
    public function tenantPartFor(string $tenantId): string;

    /**
     * Revoke every refresh family of this terminal in this tenant, and say
     * how many rows that changed.
     *
     * Idempotent: a second call changes nothing and returns 0. It touches
     * rows of principal type `terminal` only, and only for this
     * `principal_id` and `tenant_id`.
     */
    public function revokeAll(string $tenantId, string $terminalId): int;
}
