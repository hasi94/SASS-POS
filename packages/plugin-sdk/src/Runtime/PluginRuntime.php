<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Runtime;

use Flexipos\PluginSdk\Gateway\Registration;
use Flexipos\PluginSdk\Gateway\TenantHookTable;
use Flexipos\PluginSdk\Support\Connections;
use Psr\Log\LoggerInterface;

/**
 * Loads a tenant's enabled plugins, on **every** dispatch - no cache, no
 * memo (T-019 AC-17, AC-35 to AC-37, plan Ruling 10).
 *
 * A state change made by another process (a trip, a disable) is seen on
 * the very next call. The cost is one indexed master read per dispatch.
 */
final class PluginRuntime
{
    public function __construct(
        private readonly Connections $connections,
        private readonly ManifestCache $cache,
        private readonly LoggerInterface $logger,
    ) {}

    public function tableFor(string $tenantId): TenantHookTable
    {
        $master = $this->connections->master();

        $rows = $master->table('tenant_plugins as tp')
            ->join('plugins as p', 'tp.plugin_id', '=', 'p.id')
            ->join('plugin_versions as v', 'tp.plugin_version_id', '=', 'v.id')
            ->where('tp.tenant_id', $tenantId)
            ->whereIn('tp.status', ['enabled', 'error'])
            ->orderBy('tp.enabled_at')
            ->orderBy('p.name')
            ->select(['tp.plugin_id as plugin_id', 'tp.status as status', 'p.name as plugin_name', 'v.id as version_id', 'v.version as version', 'v.manifest as manifest'])
            ->get();

        $byPoint = [];
        $claimedOverrides = [];

        foreach ($rows as $row) {
            $manifest = $this->cache->get((string) $row->version_id, (string) $row->manifest, (string) $row->plugin_name);

            if ($manifest === null) {
                // AC-37: isolated to its own plugin, every other row still loads.
                continue;
            }

            $refused = (string) $row->status === 'error';

            foreach ($manifest->hooks as $hook) {
                if ($hook->mode->value === 'override') {
                    if (isset($claimedOverrides[$hook->point])) {
                        $this->logger->error('Two enabled plugins both override the same point; only the earlier one loaded.', [
                            'point' => $hook->point,
                            'holder' => $claimedOverrides[$hook->point],
                            'ignored' => $row->plugin_name,
                        ]);

                        continue;
                    }

                    $claimedOverrides[$hook->point] = $row->plugin_name;
                }

                $byPoint[$hook->point][] = new Registration(
                    plugin: (string) $row->plugin_name,
                    pluginId: (string) $row->plugin_id,
                    pluginVersion: (string) $row->version,
                    tenantId: $tenantId,
                    mode: $hook->mode,
                    handlerClass: $hook->handler,
                    refused: $refused,
                );
            }
        }

        return new TenantHookTable($byPoint);
    }
}
