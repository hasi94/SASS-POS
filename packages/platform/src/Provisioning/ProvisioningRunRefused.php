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
