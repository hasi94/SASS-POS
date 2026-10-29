<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Plugins;

/**
 * An `override` handler (T-019 AC-25): replaces the point's own action,
 * permitted only on a point explicitly marked overridable
 * ({@see HookPoint::isOverridable()}).
 */
interface OverrideHook
{
    public function override(HookCall $call): mixed;
}
