<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Features\GrantPluginAccess;

final class GrantPluginAccess
{
    public function __construct(
        public readonly string $pluginName,
        public readonly string $tenantId,
        public readonly ?string $grantedBy = null,
    ) {}
}
