<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Gateway;

use Flexipos\Contracts\Plugins\HookMode;

/**
 * One plugin's resolved handler at one point, for the current tenant
 * (T-019 plan Ruling 10).
 *
 * `$refused` is true for a plugin whose `tenant_plugins.status` is
 * `error` (the breaker is tripped): the registration still exists, so the
 * invoker can log the refusal, but the handler is never called (AC-31).
 */
final readonly class Registration
{
    public function __construct(
        public string $plugin,
        public string $pluginId,
        public string $pluginVersion,
        public string $tenantId,
        public HookMode $mode,
        public string $handlerClass,
        public bool $refused = false,
    ) {}
}
