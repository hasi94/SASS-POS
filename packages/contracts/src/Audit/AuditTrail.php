<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Audit;

/**
 * The one writer of `audit_logs`, as a contract (T-010 D-20, T-014 AC-20).
 *
 * Implemented by access's `AuditWriter`, which already owns the actor-name
 * snapshot, the derived `changed_fields`, the clock and the caller's
 * address. A second writer in organization would be a second answer to every
 * one of those.
 *
 * Runs on the tenant connection the caller has entered and **inside the
 * caller's transaction**, so a rollback takes the audit row with it.
 */
interface AuditTrail
{
    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function record(
        string $module,
        ?string $actorId,
        string $action,
        string $subjectType,
        string $subjectId,
        ?array $before,
        ?array $after,
        ?string $branchId = null,
    ): void;
}
