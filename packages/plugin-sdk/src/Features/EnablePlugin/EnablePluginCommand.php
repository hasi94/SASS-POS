<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Features\EnablePlugin;

use Flexipos\PluginSdk\Exceptions\ManifestRefused;
use Flexipos\PluginSdk\Exceptions\PluginRefused;
use Illuminate\Console\Command;

/**
 * `php artisan plugin:enable {plugin} {--tenant=} {--plugin-version=} {--as=}`
 * (T-019 AC-42).
 */
final class EnablePluginCommand extends Command
{
    protected $signature = 'plugin:enable {plugin : The plugin name}
        {--tenant= : The tenant id}
        {--plugin-version= : The version to enable; the latest when omitted}
        {--as= : The installing user id}';

    protected $description = 'Enable a plugin for a tenant';

    public function handle(EnablePluginHandler $handler): int
    {
        try {
            $result = $handler->handle(new EnablePlugin(
                pluginName: (string) $this->argument('plugin'),
                tenantId: (string) $this->option('tenant'),
                pluginVersion: $this->option('plugin-version') === null ? null : (string) $this->option('plugin-version'),
                installedBy: $this->option('as') === null ? null : (string) $this->option('as'),
            ));
        } catch (ManifestRefused $refused) {
            $this->components->error("Manifest refused [{$refused->reason}]: {$refused->getMessage()}");

            return self::FAILURE;
        } catch (PluginRefused $refused) {
            $this->components->error("Refused [{$refused->reason}]: {$refused->getMessage()}");

            return self::FAILURE;
        }

        $this->components->info(
            $result->noop
                ? "Plugin {$this->argument('plugin')} is already enabled (schema_version {$result->schemaVersion})."
                : "Enabled {$this->argument('plugin')} (schema_version {$result->schemaVersion})."
        );

        return self::SUCCESS;
    }
}
