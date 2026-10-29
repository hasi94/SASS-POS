<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Features\GrantPluginAccess;

final class GrantPluginAccessResult
{
    public function __construct(
        public readonly string $pluginId,
        public readonly string $tenantId,
        public readonly bool $alreadyGranted,
    ) {}
}
