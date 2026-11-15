<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Features\DisablePlugin;

use Flexipos\PluginSdk\Exceptions\PluginRefused;
use Illuminate\Console\Command;

/**
 * `php artisan plugin:disable {plugin} {--tenant=}` (T-019 AC-42).
 */
final class DisablePluginCommand extends Command
{
    protected $signature = 'plugin:disable {plugin : The plugin name} {--tenant= : The tenant id}';

    protected $description = 'Disable a plugin for a tenant, without dropping its tables';

    public function handle(DisablePluginHandler $handler): int
    {
        try {
            $result = $handler->handle(new DisablePlugin(
                pluginName: (string) $this->argument('plugin'),
                tenantId: (string) $this->option('tenant'),
            ));
        } catch (PluginRefused $refused) {
            $this->components->error("Refused [{$refused->reason}]: {$refused->getMessage()}");

            return self::FAILURE;
        }

        $this->components->info(
            $result->noop
                ? "Plugin {$this->argument('plugin')} is already disabled."
                : "Disabled {$this->argument('plugin')}."
        );

        return self::SUCCESS;
    }
}
