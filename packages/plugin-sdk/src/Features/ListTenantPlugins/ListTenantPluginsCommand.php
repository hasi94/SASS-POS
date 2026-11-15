<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Features\ListTenantPlugins;

use Illuminate\Console\Command;

/**
 * `php artisan plugin:list {--tenant=}` (T-019 AC-40).
 */
final class ListTenantPluginsCommand extends Command
{
    protected $signature = 'plugin:list {--tenant= : The tenant id}';

    protected $description = 'List the plugins installed, enabled, disabled or tripped for a tenant';

    public function handle(ListTenantPluginsHandler $handler): int
    {
        $result = $handler->handle(new ListTenantPlugins((string) $this->option('tenant')));

        $this->table(
            ['Plugin', 'Version', 'Status', 'Schema', 'Failures', 'Tripped at', 'Last error', 'Enabled at'],
            array_map(fn (array $row): array => [
                $row['plugin'], $row['version'], $row['status'], $row['schema_version'],
                $row['failure_count'], $row['tripped_at'] ?? '-', $row['last_error'] ?? '-', $row['enabled_at'] ?? '-',
            ], $result->rows)
        );

        return self::SUCCESS;
    }
}
