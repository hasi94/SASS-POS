<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Features\RegisterPluginVersion;

use Flexipos\PluginSdk\Exceptions\ManifestRefused;
use Flexipos\PluginSdk\Exceptions\PluginRefused;
use Illuminate\Console\Command;

/**
 * `php artisan plugin:register {path}` (T-019 plan Ruling 7).
 */
final class RegisterPluginVersionCommand extends Command
{
    protected $signature = 'plugin:register {path : The plugin package directory, holding plugin.json}';

    protected $description = "Register a plugin version from its package directory's plugin.json";

    public function handle(RegisterPluginVersionHandler $handler): int
    {
        try {
            $result = $handler->handle(new RegisterPluginVersion((string) $this->argument('path')));
        } catch (ManifestRefused $refused) {
            $this->components->error("Manifest refused [{$refused->reason}]: {$refused->getMessage()}");

            return self::FAILURE;
        } catch (PluginRefused $refused) {
            $this->components->error("Refused [{$refused->reason}]: {$refused->getMessage()}");

            return self::FAILURE;
        }

        $this->components->info("Registered {$result->name} {$result->version} (plugin {$result->pluginId}, version {$result->versionId}).");

        return self::SUCCESS;
    }
}
