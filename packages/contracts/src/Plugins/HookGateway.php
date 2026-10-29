<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Plugins;

use Closure;

/**
 * The one call per extension point (T-019 AC-21, plan Ruling 1 and 4).
 *
 * Runs `before`, then `filter`, then (`override` or `$action`), then `add`,
 * then `after`, for every plugin enabled for the current tenant that
 * registered a handler at `$point`, in registration order. With zero
 * registrations, this is exactly `$action($payload)` (AC-26).
 */
interface HookGateway
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function dispatch(string $point, array $payload = [], ?Closure $action = null): HookOutcome;
}
