<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Gateway;

/**
 * The real {@see HookClock}: `hrtime(true)`, converted to milliseconds.
 */
final class SystemHookClock implements HookClock
{
    public function nowMs(): float
    {
        return hrtime(true) / 1_000_000;
    }
}
