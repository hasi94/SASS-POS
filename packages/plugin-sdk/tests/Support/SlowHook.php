<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Tests\Support;

use Flexipos\Contracts\Plugins\BeforeHook;
use Flexipos\Contracts\Plugins\HookCall;
use Flexipos\Contracts\Plugins\Veto;
use Flexipos\PluginSdk\Gateway\HookClock;

/**
 * A `before` handler that advances the bound {@see FakeHookClock} past the
 * configured timeout, so the timeout path is provable with no real wait
 * (T-019 D-5, plan Ruling 5).
 *
 * Takes the published `HookClock` port - the same instance `HookInvoker`
 * measures against, because resolving `FakeHookClock::class` directly
 * would build a second, unbound instance instead of the one the test
 * bound to the interface.
 */
final class SlowHook implements BeforeHook
{
    public static int $callCount = 0;

    public static function reset(): void
    {
        self::$callCount = 0;
    }

    public function __construct(
        private readonly HookClock $clock,
    ) {}

    public function before(HookCall $call): ?Veto
    {
        self::$callCount++;

        if ($this->clock instanceof FakeHookClock) {
            $this->clock->advanceMs(600);
        }

        return null;
    }
}
