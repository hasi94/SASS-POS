<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Features\ListTenantPlugins;

final class ListTenantPlugins
{
    public function __construct(
        public readonly string $tenantId,
    ) {}
}
