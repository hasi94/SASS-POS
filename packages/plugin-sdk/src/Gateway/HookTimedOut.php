<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Gateway;

use RuntimeException;

/**
 * Thrown inside a handler's own savepoint once its elapsed time exceeds
 * `config('plugins.timeout_ms')` (T-019 D-5, plan Ruling 5).
 *
 * Measured **after** the call completes, never during it: PHP cannot
 * portably interrupt a running call (no `pcntl_alarm` on Windows), so a
 * handler that never returns is not stopped by this - only
 * `max_execution_time` is. A slow handler's result is discarded and its
 * writes are rolled back by the savepoint this is thrown inside.
 */
final class HookTimedOut extends RuntimeException
{
    public static function after(int $elapsedMs, int $limitMs): self
    {
        return new self("A plugin hook handler took {$elapsedMs}ms, over the {$limitMs}ms limit.");
    }
}
