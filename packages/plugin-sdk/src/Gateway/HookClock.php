<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Gateway;

/**
 * An internal port over "how much time has passed", so a test can advance
 * it without really waiting (T-019 plan Ruling 5).
 */
interface HookClock
{
    /**
     * Milliseconds, with sub-millisecond precision - not wall-clock time,
     * only ever used as a difference between two calls.
     */
    public function nowMs(): float;
}
