<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Features\DisablePlugin;

final class DisablePluginResult
{
    public function __construct(
        public readonly string $tenantPluginId,
        public readonly bool $noop,
    ) {}
}
