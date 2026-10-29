<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Exceptions;

use Flexipos\PluginSdk\Runtime\PluginAfterHookConsumer;
use RuntimeException;

/**
 * Thrown by {@see PluginAfterHookConsumer} for a
 * tripped plugin (T-019 plan Ruling 11): T-018's runner leaves the
 * message unacknowledged, and after `max_deliveries` it dead-letters it,
 * so an operator can `eventbus:replay` it after `plugin:reset`.
 */
final class PluginDispatchRefused extends RuntimeException
{
    public static function tripped(string $pluginName, string $tenantId): self
    {
        return new self("Plugin [{$pluginName}] is tripped for tenant [{$tenantId}].");
    }
}
