<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Features\ResetPluginBreaker;

use Flexipos\PluginSdk\Exceptions\PluginRefused;
use Illuminate\Console\Command;

/**
 * `php artisan plugin:reset {plugin} {--tenant=}` (T-019 AC-41).
 */
final class ResetPluginBreakerCommand extends Command
{
    protected $signature = 'plugin:reset {plugin : The plugin name} {--tenant= : The tenant id}';

    protected $description = 'Reset a tripped plugin breaker back to enabled';

    public function handle(ResetPluginBreakerHandler $handler): int
    {
        try {
            $handler->handle(new ResetPluginBreaker(
                pluginName: (string) $this->argument('plugin'),
                tenantId: (string) $this->option('tenant'),
            ));
        } catch (PluginRefused $refused) {
            $this->components->error("Refused [{$refused->reason}]: {$refused->getMessage()}");

            return self::FAILURE;
        }

        $this->components->info("Reset {$this->argument('plugin')}.");

        return self::SUCCESS;
    }
}
