<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Tests\Support;

use Flexipos\Contracts\Plugins\AddHook;
use Flexipos\Contracts\Plugins\AfterHook;
use Flexipos\Contracts\Plugins\AfterHookCall;
use Flexipos\Contracts\Plugins\BeforeHook;
use Flexipos\Contracts\Plugins\FilterHook;
use Flexipos\Contracts\Plugins\HookCall;
use Flexipos\Contracts\Plugins\OverrideHook;
use Flexipos\Contracts\Plugins\Veto;
use RuntimeException;

/**
 * A handler that always throws, registrable at any mode (T-019 AC-30 to
 * AC-34's breaker tests).
 */
final class ThrowingHook implements AddHook, AfterHook, BeforeHook, FilterHook, OverrideHook
{
    public static int $callCount = 0;

    public static function reset(): void
    {
        self::$callCount = 0;
    }

    public function add(HookCall $call, mixed $result): mixed
    {
        self::$callCount++;

        throw new RuntimeException('ThrowingHook always fails.');
    }

    public function before(HookCall $call): ?Veto
    {
        self::$callCount++;

        throw new RuntimeException('ThrowingHook always fails.');
    }

    public function filter(HookCall $call): array
    {
        self::$callCount++;

        throw new RuntimeException('ThrowingHook always fails.');
    }

    public function override(HookCall $call): mixed
    {
        self::$callCount++;

        throw new RuntimeException('ThrowingHook always fails.');
    }

    public function after(AfterHookCall $call): void
    {
        self::$callCount++;

        throw new RuntimeException('ThrowingHook always fails.');
    }
}
