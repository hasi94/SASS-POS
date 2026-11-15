<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Tests\Support;

use Flexipos\Contracts\Plugins\BeforeHook;
use Flexipos\Contracts\Plugins\HookCall;
use Flexipos\Contracts\Plugins\Veto;
use Flexipos\Contracts\Plugins\VetoSignal;
use Flexipos\PluginSdk\Gateway\HookClock;

/**
 * A `before` handler that is both slow (past the configured timeout) and
 * returns - rather than throws - a {@see Veto}. Proves T-019 W-1: a
 * returned veto must block the gated action even when it is also over
 * budget, the same as a thrown {@see VetoSignal}
 * already does. Without the fix this instead fails open via a `timeout`
 * outcome (Gateway.php's `'timeout' => $failed[]`, never a veto).
 */
final class SlowVetoBeforeHook implements BeforeHook
{
    public function __construct(
        private readonly HookClock $clock,
    ) {}

    public function before(HookCall $call): Veto
    {
        if ($this->clock instanceof FakeHookClock) {
            $this->clock->advanceMs(600);
        }

        return new Veto('fixture-slow-veto refuses this payload, slowly.');
    }
}
