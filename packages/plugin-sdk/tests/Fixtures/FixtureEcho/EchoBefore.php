<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Tests\Fixtures\FixtureEcho;

use Flexipos\Contracts\Plugins\BeforeHook;
use Flexipos\Contracts\Plugins\HookCall;
use Flexipos\Contracts\Plugins\Veto;

/**
 * The proof-of-pipeline fixture's `before` handler (T-019 AC-23, AC-44).
 * Vetoes exactly when the payload says `veto: true`, so one manifest can
 * prove both the allowing and the vetoing path.
 */
final class EchoBefore implements BeforeHook
{
    public function before(HookCall $call): ?Veto
    {
        if (($call->payload['veto'] ?? false) === true) {
            return new Veto('fixture-echo refuses this payload.');
        }

        return null;
    }
}
