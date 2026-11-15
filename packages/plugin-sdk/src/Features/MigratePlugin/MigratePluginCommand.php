<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Features\MigratePlugin;

use Flexipos\PluginSdk\Exceptions\PluginRefused;
use Illuminate\Console\Command;

/**
 * `php artisan plugin:migrate {plugin} {--tenant=*}` (`docs/database.md`
 * §9.6).
 */
final class MigratePluginCommand extends Command
{
    protected $signature = 'plugin:migrate {plugin : The plugin name} {--tenant=* : One or more tenant ids; every enabled tenant when omitted}';

    protected $description = "Run a plugin's own tenant migrations";

    public function handle(MigratePluginHandler $handler): int
    {
        $tenants = $this->option('tenant');

        try {
            $result = $handler->handle(new MigratePlugin(
                pluginName: (string) $this->argument('plugin'),
                tenantIds: $tenants === [] ? null : array_map('strval', $tenants),
            ));
        } catch (PluginRefused $refused) {
            $this->components->error("Refused [{$refused->reason}]: {$refused->getMessage()}");

            return self::FAILURE;
        }

        foreach ($result->applied as $tenantId => $schemaVersion) {
            $this->components->info("Tenant {$tenantId}: schema_version {$schemaVersion}.");
        }

        foreach ($result->failed as $tenantId => $message) {
            $this->components->error("Tenant {$tenantId}: {$message}");
        }

        return $result->failed === [] ? self::SUCCESS : self::FAILURE;
    }
}
