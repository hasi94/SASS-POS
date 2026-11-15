<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Tests\Support;

use Flexipos\Contracts\Plugins\AddHook;
use Flexipos\Contracts\Plugins\HookCall;

/**
 * A second plugin's `add` handler, distinct from `fixture-echo`'s own
 * `EchoAdd` (T-019 AC-21: registration order across plugins).
 */
final class SecondPluginAdd implements AddHook
{
    public function add(HookCall $call, mixed $result): mixed
    {
        return 'added-by-'.$call->plugin;
    }
}
