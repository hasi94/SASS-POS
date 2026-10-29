<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Features\UninstallPlugin;

use Flexipos\PluginSdk\Exceptions\PluginRefused;
use Illuminate\Console\Command;

/**
 * `php artisan plugin:uninstall {plugin} {--tenant=}` (T-019 AC-20).
 */
final class UninstallPluginCommand extends Command
{
    protected $signature = 'plugin:uninstall {plugin : The plugin name} {--tenant= : The tenant id}';

    protected $description = "Export and drop a plugin's own tenant-side tables, then remove its master row";

    public function handle(UninstallPluginHandler $handler): int
    {
        try {
            $result = $handler->handle(new UninstallPlugin(
                pluginName: (string) $this->argument('plugin'),
                tenantId: (string) $this->option('tenant'),
            ));
        } catch (PluginRefused $refused) {
            $this->components->error("Refused [{$refused->reason}]: {$refused->getMessage()}");

            return self::FAILURE;
        }

        $this->components->info('Uninstalled '.$this->argument('plugin').'. Dropped: '.implode(', ', $result->droppedTables).'.');

        return self::SUCCESS;
    }
}
