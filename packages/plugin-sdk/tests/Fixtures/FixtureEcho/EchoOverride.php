<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Tests\Fixtures\FixtureEcho;

use Flexipos\Contracts\Plugins\HookCall;
use Flexipos\Contracts\Plugins\OverrideHook;

/**
 * The proof-of-pipeline fixture's `override` handler (T-019 AC-25, AC-44),
 * registered against `fixture.echo`, the point `FixtureHookPointProvider`
 * marks overridable.
 */
final class EchoOverride implements OverrideHook
{
    public function override(HookCall $call): mixed
    {
        return ['overridden_by' => $call->plugin, 'payload' => $call->payload];
    }
}
