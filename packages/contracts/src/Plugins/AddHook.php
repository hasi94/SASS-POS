<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Plugins;

/**
 * An `add` handler (T-019 AC-22): invoked alongside the point's own action,
 * contributing a value it can neither veto nor replace.
 */
interface AddHook
{
    public function add(HookCall $call, mixed $result): mixed;
}
