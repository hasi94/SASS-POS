<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Breaker;

use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\PluginSdk\Support\Connections;
use Psr\Log\LoggerInterface;

/**
 * Per (tenant, plugin) failure counting on `tenant_plugins`'s own columns
 * (T-019 D-6, AC-30 to AC-34, plan Ruling 12).
 *
 * **Two statements, not one `UPDATE … CASE` expression.** MySQL evaluates
 * a single-table `UPDATE … SET` left to right using values already
 * updated; SQLite does not. Two plain statements behave the same on
 * both engines.
 *
 * **The only writer** of `failure_count`, `tripped_at`, `last_error`, and
 * of `status` to and from `error` - enforced structurally by
 * `QaPluginSourceRulesTest`, step 15.
 */
final class CircuitBreaker
{
    public function __construct(
        private readonly Connections $connections,
        private readonly RunnerClock $clock,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @return bool whether this call is the one that tripped the breaker
     */
    public function recordFailure(string $tenantId, string $pluginId, string $error): bool
    {
        $master = $this->connections->master();
        $now = $this->clock->timestamp();

        $master->table('tenant_plugins')
            ->where('tenant_id', $tenantId)
            ->where('plugin_id', $pluginId)
            ->increment('failure_count', 1, [
                'last_error' => $error,
                'updated_at' => $now,
            ]);

        $threshold = (int) config('plugins.breaker.threshold', 5);

        $tripped = $master->table('tenant_plugins')
            ->where('tenant_id', $tenantId)
            ->where('plugin_id', $pluginId)
            ->where('status', 'enabled')
            ->where('failure_count', '>=', $threshold)
            ->update(['status' => 'error', 'tripped_at' => $now, 'updated_at' => $now]);

        if ($tripped > 0) {
            $this->logger->critical('A plugin circuit breaker tripped.', [
                'tenant_id' => $tenantId,
                'plugin_id' => $pluginId,
                'last_error' => $error,
            ]);

            return true;
        }

        return false;
    }

    public function recordSuccess(string $tenantId, string $pluginId): void
    {
        $this->connections->master()->table('tenant_plugins')
            ->where('tenant_id', $tenantId)
            ->where('plugin_id', $pluginId)
            ->where('failure_count', '>', 0)
            ->update(['failure_count' => 0, 'updated_at' => $this->clock->timestamp()]);
    }

    public function reset(string $tenantId, string $pluginId): bool
    {
        $affected = $this->connections->master()->table('tenant_plugins')
            ->where('tenant_id', $tenantId)
            ->where('plugin_id', $pluginId)
            ->where('status', 'error')
            ->update([
                'status' => 'enabled',
                'failure_count' => 0,
                'tripped_at' => null,
                'last_error' => null,
                'updated_at' => $this->clock->timestamp(),
            ]);

        return $affected > 0;
    }
}
