<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Features\UninstallPlugin;

final class UninstallPlugin
{
    public function __construct(
        public readonly string $pluginName,
        public readonly string $tenantId,
    ) {}
}
