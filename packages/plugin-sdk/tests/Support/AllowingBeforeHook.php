<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Tests\Support;

use Flexipos\Contracts\Plugins\BeforeHook;
use Flexipos\Contracts\Plugins\HookCall;
use Flexipos\Contracts\Plugins\Veto;

/**
 * A `before` handler that never vetoes, counting its calls (T-019 AC-23:
 * "allows the dispatch to continue, then the next before handler runs").
 */
final class AllowingBeforeHook implements BeforeHook
{
    public static int $callCount = 0;

    public static function reset(): void
    {
        self::$callCount = 0;
    }

    public function before(HookCall $call): ?Veto
    {
        self::$callCount++;

        return null;
    }
}
