<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Tests\Support;

use Flexipos\Contracts\Plugins\BeforeHook;
use Flexipos\Contracts\Plugins\HookCall;
use Flexipos\Contracts\Plugins\Veto;
use Flexipos\Contracts\Plugins\VetoSignal;

/**
 * The other way a `before` handler may veto: throwing instead of
 * returning (T-019 AC-23: "returning/throwing").
 */
final class ThrowingVetoBeforeHook implements BeforeHook
{
    public function before(HookCall $call): ?Veto
    {
        throw new VetoSignal('fixture-echo-throws refuses this payload.');
    }
}
