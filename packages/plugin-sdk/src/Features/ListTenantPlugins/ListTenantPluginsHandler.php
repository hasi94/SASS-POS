<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Features\ListTenantPlugins;

use Flexipos\PluginSdk\Support\Connections;

/**
 * `plugin:list {--tenant=}` (T-019 AC-40). Enough of each row for an
 * operator to decide what to do next, ordered by plugin name.
 */
final class ListTenantPluginsHandler
{
    public function __construct(
        private readonly Connections $connections,
    ) {}

    public function handle(ListTenantPlugins $command): ListTenantPluginsResult
    {
        $rows = $this->connections->master()->table('tenant_plugins as tp')
            ->join('plugins as p', 'tp.plugin_id', '=', 'p.id')
            ->join('plugin_versions as v', 'tp.plugin_version_id', '=', 'v.id')
            ->where('tp.tenant_id', $command->tenantId)
            ->orderBy('p.name')
            ->select([
                'p.name as plugin', 'v.version as version', 'tp.status as status',
                'tp.schema_version as schema_version', 'tp.failure_count as failure_count',
                'tp.tripped_at as tripped_at', 'tp.last_error as last_error', 'tp.enabled_at as enabled_at',
            ])
            ->get();

        return new ListTenantPluginsResult($rows->map(fn (object $row): array => [
            'plugin' => (string) $row->plugin,
            'version' => (string) $row->version,
            'status' => (string) $row->status,
            'schema_version' => (int) $row->schema_version,
            'failure_count' => (int) $row->failure_count,
            'tripped_at' => $row->tripped_at,
            'last_error' => $row->last_error,
            'enabled_at' => $row->enabled_at,
        ])->all());
    }
}
