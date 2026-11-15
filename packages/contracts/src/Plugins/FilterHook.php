<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Plugins;

/**
 * A `filter` handler (T-019 AC-24): receives the current payload and returns
 * the next one, so filters chain in registration order.
 */
interface FilterHook
{
    /**
     * @return array<string, mixed>
     */
    public function filter(HookCall $call): array;
}
