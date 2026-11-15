<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Tests\Support;

use Flexipos\PluginSdk\Gateway\HookClock;

/**
 * A {@see HookClock} a test moves on demand, so nothing in the timeout
 * tests really waits (T-019 plan Ruling 5).
 */
final class FakeHookClock implements HookClock
{
    private float $nowMs = 0.0;

    public function nowMs(): float
    {
        return $this->nowMs;
    }

    public function advanceMs(float $ms): void
    {
        $this->nowMs += $ms;
    }
}
