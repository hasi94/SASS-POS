<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Features\MigratePlugin;

final class MigratePlugin
{
    /**
     * @param  ?list<string>  $tenantIds  null walks every tenant whose row is `enabled`
     */
    public function __construct(
        public readonly string $pluginName,
        public readonly ?array $tenantIds = null,
    ) {}
}
