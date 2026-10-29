<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Features\EnablePlugin;

final class EnablePlugin
{
    public function __construct(
        public readonly string $pluginName,
        public readonly string $tenantId,
        public readonly ?string $pluginVersion = null,
        public readonly ?string $installedBy = null,
    ) {}
}
