<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Plugins;

/**
 * Implemented by a module's (or the plugin SDK's own) service provider to
 * declare the hook points it owns (T-019 plan Ruling 1 and 4).
 *
 * Collected through `$app->getProviders()`, the way
 * `Flexipos\Contracts\Events\DeclaresEventConsumers` is collected: a point
 * owner declares its own catalogue entry rather than a registry restating a
 * list that would go out of step with it.
 */
interface DeclaresHookPoints
{
    /**
     * @return list<HookPoint>
     */
    public function hookPoints(): array;
}
