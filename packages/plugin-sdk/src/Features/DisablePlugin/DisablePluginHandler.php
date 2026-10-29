<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Features\DisablePlugin;

use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\PluginSdk\Exceptions\PluginRefused;
use Flexipos\PluginSdk\Support\Connections;

/**
 * `plugin:disable {plugin} {--tenant=}` (T-019 AC-15, AC-42; plan
 * Ruling 8). Never drops a table - that is `UninstallPlugin`'s job.
 */
final class DisablePluginHandler
{
    public function __construct(
        private readonly Connections $connections,
        private readonly RunnerClock $clock,
    ) {}

    public function handle(DisablePlugin $command): DisablePluginResult
    {
        $master = $this->connections->master();

        $plugin = $master->table('plugins')->where('name', $command->pluginName)->first();

        if ($plugin === null) {
            throw PluginRefused::unknownPlugin($command->pluginName);
        }

        $row = $master->table('tenant_plugins')
            ->where('tenant_id', $command->tenantId)
            ->where('plugin_id', $plugin->id)
            ->first();

        if ($row === null) {
            throw PluginRefused::unknownPlugin($command->pluginName);
        }

        if ((string) $row->status === 'disabled') {
            return new DisablePluginResult((string) $row->id, true);
        }

        if ((string) $row->status !== 'enabled') {
            throw PluginRefused::cannotDisable($command->pluginName, (string) $row->status);
        }

        $now = $this->clock->timestamp();

        $master->table('tenant_plugins')->where('id', $row->id)->update([
            'status' => 'disabled',
            'disabled_at' => $now,
            'updated_at' => $now,
        ]);

        return new DisablePluginResult((string) $row->id, false);
    }
}
