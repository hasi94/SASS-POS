<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Tests\Fixtures\FixtureEcho;

use Flexipos\Contracts\Plugins\AddHook;
use Flexipos\Contracts\Plugins\HookCall;

/**
 * The proof-of-pipeline fixture's `add` handler (T-019 AC-22, AC-44). Not a
 * reference plugin - see `plugin.json`'s header.
 */
final class EchoAdd implements AddHook
{
    public function add(HookCall $call, mixed $result): mixed
    {
        return 'added-by-'.$call->plugin;
    }
}
