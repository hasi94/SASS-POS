<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Features\GrantPluginAccess;

use Flexipos\PluginSdk\Exceptions\PluginRefused;
use Illuminate\Console\Command;

/**
 * `php artisan plugin:grant {plugin} {--tenant=} {--as=}` (T-019 AC-11).
 */
final class GrantPluginAccessCommand extends Command
{
    protected $signature = 'plugin:grant {plugin : The plugin name}
        {--tenant= : The tenant id to grant access to}
        {--as= : The granting user id, recorded on the row}';

    protected $description = 'Grant a private plugin access to one tenant';

    public function handle(GrantPluginAccessHandler $handler): int
    {
        try {
            $result = $handler->handle(new GrantPluginAccess(
                pluginName: (string) $this->argument('plugin'),
                tenantId: (string) $this->option('tenant'),
                grantedBy: $this->option('as') === null ? null : (string) $this->option('as'),
            ));
        } catch (PluginRefused $refused) {
            $this->components->error("Refused [{$refused->reason}]: {$refused->getMessage()}");

            return self::FAILURE;
        }

        $this->components->info(
            $result->alreadyGranted
                ? "Tenant {$result->tenantId} already has access to plugin {$result->pluginId}."
                : "Granted tenant {$result->tenantId} access to plugin {$result->pluginId}."
        );

        return self::SUCCESS;
    }
}
