<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Gateway;

use Flexipos\Contracts\Plugins\HookMode;
use Flexipos\Kernel\Http\RequestId;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\PluginSdk\Support\Connections;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of `plugin_execution_logs` (T-019 AC-30, plan Ruling 5;
 * enforced structurally by `QaPluginSourceRulesTest`, step 15).
 */
final class ExecutionLog
{
    public function __construct(
        private readonly Connections $connections,
        private readonly RunnerClock $clock,
        private readonly RequestId $requestId,
    ) {}

    public function write(string $pluginName, string $point, HookMode $mode, int $durationMs, string $status, ?string $error): void
    {
        DB::connection($this->connections->tenantConnectionName())->table('plugin_execution_logs')->insert([
            'plugin_name' => $pluginName,
            'point' => $point,
            'mode' => $mode->value,
            'duration_ms' => $durationMs,
            'status' => $status,
            'error' => $error === null ? null : substr($error, 0, 1000),
            'request_id' => $this->requestId->current(),
            'created_at' => $this->clock->timestamp(),
        ]);
    }
}
