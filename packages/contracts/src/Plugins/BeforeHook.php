<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Plugins;

/**
 * A `before` handler (T-019 AC-23): may veto by returning a {@see Veto} or
 * throwing a {@see VetoSignal}; returning null allows the dispatch to
 * proceed to the next `before` handler, then the point's own action.
 */
interface BeforeHook
{
    public function before(HookCall $call): ?Veto;
}
