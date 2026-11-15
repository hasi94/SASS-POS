<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Features\ResetPluginBreaker;

use Flexipos\PluginSdk\Breaker\CircuitBreaker;
use Flexipos\PluginSdk\Exceptions\PluginRefused;
use Flexipos\PluginSdk\Support\Connections;

/**
 * `plugin:reset {plugin} {--tenant=}` (T-019 AC-33, AC-41; plan
 * Ruling 12). The only deliberate, operator-triggered way out of
 * `status='error'` - there is no automatic, time-based reset (D-6).
 */
final class ResetPluginBreakerHandler
{
    public function __construct(
        private readonly Connections $connections,
        private readonly CircuitBreaker $breaker,
    ) {}

    public function handle(ResetPluginBreaker $command): void
    {
        $plugin = $this->connections->master()->table('plugins')->where('name', $command->pluginName)->first();

        if ($plugin === null) {
            throw PluginRefused::unknownPlugin($command->pluginName);
        }

        if (! $this->breaker->reset($command->tenantId, (string) $plugin->id)) {
            throw PluginRefused::notTripped($command->pluginName);
        }
    }
}
