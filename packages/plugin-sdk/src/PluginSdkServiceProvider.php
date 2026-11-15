<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk;

use Flexipos\Contracts\Events\DeclaresEventConsumers;
use Flexipos\Contracts\Events\EventConsumer;
use Flexipos\Contracts\Plugins\HookGateway;
use Flexipos\PluginSdk\Features\DisablePlugin\DisablePluginCommand;
use Flexipos\PluginSdk\Features\EnablePlugin\EnablePluginCommand;
use Flexipos\PluginSdk\Features\GrantPluginAccess\GrantPluginAccessCommand;
use Flexipos\PluginSdk\Features\ListTenantPlugins\ListTenantPluginsCommand;
use Flexipos\PluginSdk\Features\MigratePlugin\MigratePluginCommand;
use Flexipos\PluginSdk\Features\RegisterPluginVersion\RegisterPluginVersionCommand;
use Flexipos\PluginSdk\Features\ResetPluginBreaker\ResetPluginBreakerCommand;
use Flexipos\PluginSdk\Features\UninstallPlugin\UninstallPluginCommand;
use Flexipos\PluginSdk\Gateway\Gateway;
use Flexipos\PluginSdk\Gateway\HookClock;
use Flexipos\PluginSdk\Gateway\HookPointCatalogue;
use Flexipos\PluginSdk\Gateway\SystemHookClock;
use Flexipos\PluginSdk\Runtime\ManifestCache;
use Flexipos\PluginSdk\Runtime\PluginAfterHookConsumer;
use Flexipos\PluginSdk\Support\JsonFilePluginDataExporter;
use Flexipos\PluginSdk\Support\PluginDataExporter;
use Illuminate\Support\ServiceProvider;

/**
 * The plugin SDK package's entry point into the host application (T-019
 * plan Ruling 1 and step 1).
 *
 * `implements DeclaresEventConsumers` so `plugins.after`
 * ({@see PluginAfterHookConsumer}) is collected by T-018's
 * `ConsumerRegistry`, the same way `Flexipos\Access\Support\AuditCatalogue`
 * collects `DeclaresAuditActions` (plan Ruling 11). No schedule entry
 * (same precedent as `EventbusServiceProvider`).
 */
final class PluginSdkServiceProvider extends ServiceProvider implements DeclaresEventConsumers
{
    public function register(): void
    {
        $this->app->singleton(HookPointCatalogue::class);
        $this->app->singleton(ManifestCache::class);
        $this->app->bind(HookClock::class, SystemHookClock::class);
        $this->app->bind(HookGateway::class, Gateway::class);
        $this->app->bind(PluginDataExporter::class, JsonFilePluginDataExporter::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                RegisterPluginVersionCommand::class,
                GrantPluginAccessCommand::class,
                MigratePluginCommand::class,
                EnablePluginCommand::class,
                DisablePluginCommand::class,
                UninstallPluginCommand::class,
                ResetPluginBreakerCommand::class,
                ListTenantPluginsCommand::class,
            ]);
        }
    }

    /**
     * @return list<class-string<EventConsumer>>
     */
    public function eventConsumers(): array
    {
        return [
            PluginAfterHookConsumer::class,
        ];
    }
}
