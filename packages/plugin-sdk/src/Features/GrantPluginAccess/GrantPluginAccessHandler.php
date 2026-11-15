<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Features\GrantPluginAccess;

use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\PluginSdk\Exceptions\PluginRefused;
use Flexipos\PluginSdk\Support\Connections;

/**
 * `plugin:grant {plugin} {--tenant=} {--as=}` (T-019 AC-11; plan Ruling 7).
 *
 * Refuses a plugin that is not `private`, because the grant would mean
 * nothing: `official` and `third_party` plugins are not access-gated by
 * `plugin_tenant_access` at all.
 */
final class GrantPluginAccessHandler
{
    public function __construct(
        private readonly Connections $connections,
        private readonly RunnerClock $clock,
    ) {}

    public function handle(GrantPluginAccess $command): GrantPluginAccessResult
    {
        $connection = $this->connections->master();

        $plugin = $connection->table('plugins')->where('name', $command->pluginName)->first();

        if ($plugin === null) {
            throw PluginRefused::unknownPlugin($command->pluginName);
        }

        if ((string) $plugin->visibility !== 'private') {
            throw PluginRefused::notPrivate($command->pluginName);
        }

        $existing = $connection->table('plugin_tenant_access')
            ->where('plugin_id', $plugin->id)
            ->where('tenant_id', $command->tenantId)
            ->exists();

        if ($existing) {
            return new GrantPluginAccessResult($plugin->id, $command->tenantId, true);
        }

        $now = $this->clock->timestamp();

        $connection->table('plugin_tenant_access')->insert([
            'plugin_id' => $plugin->id,
            'tenant_id' => $command->tenantId,
            'granted_by' => $command->grantedBy,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return new GrantPluginAccessResult($plugin->id, $command->tenantId, false);
    }
}
