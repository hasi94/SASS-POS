<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Features\ListTenantPlugins;

final class ListTenantPluginsResult
{
    /**
     * @param  list<array{plugin: string, version: string, status: string, schema_version: int, failure_count: int, tripped_at: ?string, last_error: ?string, enabled_at: ?string}>  $rows
     */
    public function __construct(
        public readonly array $rows,
    ) {}
}
