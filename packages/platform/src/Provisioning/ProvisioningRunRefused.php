<?php

declare(strict_types=1);

namespace Flexipos\Platform\Provisioning;

use RuntimeException;

/**
 * The runner refused to claim or plan a run, and nothing was written
 * (Ruling 2's claiming half, AC-4, AC-6).
 */
final class ProvisioningRunRefused extends RuntimeException
{
    public static function notFound(string $runId): self
    {
        return new self("No provisioning run [{$runId}] exists.");
    }

    public static function alreadyCompleted(string $runId): self
    {
        return new self("Provisioning run [{$runId}] is already completed; there is nothing to resume.");
    }

    /**
     * A `rolled_back` (abandoned) run: it can never become claimable again,
     * so neither `--resume` nor a second `--abandon` should fall through to
     * {@see self::leaseHeld()}'s "wait for the staleness window" message,
     * which is meaningless for a run in this terminal state (review round 1
     * W-7).
     */
    public static function alreadyAbandoned(string $runId): self
    {
        return new self("Provisioning run [{$runId}] was already abandoned; it cannot be resumed or abandoned again.");
    }

    /**
     * The compare-and-set lease was enforced again after the initial claim
     * and lost: some other worker has since claimed this run (review round
     * 1 W-3). The process holding this exception must stop writing
     * immediately - it no longer owns the run.
     */
    public static function leaseLost(string $runId): self
    {
        return new self(
            "Provisioning run [{$runId}]'s lease was claimed by another worker; this process no longer owns it and must stop."
        );
    }

    /**
     * A non-stale `running` run, claimed by somebody else (or this same
     * process, in an earlier attempt) and not yet past the staleness
     * window.
     */
    public static function leaseHeld(string $runId, ?string $heartbeatAt): self
    {
        return new self(
            "Provisioning run [{$runId}] is still running, with a heartbeat at [".($heartbeatAt ?? 'unknown')
            .']; it is not stale yet, so it is not resumable. Wait for the staleness window to pass, or let '
            .'the running process finish.'
        );
    }
}
