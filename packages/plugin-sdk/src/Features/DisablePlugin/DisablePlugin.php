<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Features\DisablePlugin;

final class DisablePlugin
{
    public function __construct(
        public readonly string $pluginName,
        public readonly string $tenantId,
    ) {}
}
