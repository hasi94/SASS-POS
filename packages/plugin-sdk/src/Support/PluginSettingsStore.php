<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Support;

use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Per-plugin tenant settings (T-019 AC-18, plan Ruling 13).
 *
 * Internal: no published contract and no command. AC-18 asks only for
 * encryption at rest, and a later task can publish an accessor when a
 * real plugin needs one.
 */
final class PluginSettingsStore
{
    public function __construct(
        private readonly Connections $connections,
        private readonly RunnerClock $clock,
    ) {}

    public function put(string $plugin, string $key, ?string $value, bool $isSecret, ?string $updatedByEmployeeId = null): void
    {
        $connection = DB::connection($this->connections->tenantConnectionName());
        $stored = ($isSecret && $value !== null) ? Crypt::encryptString($value) : $value;
        $now = $this->clock->timestamp();

        $exists = $connection->table('plugin_settings')
            ->where('plugin_name', $plugin)
            ->where('key', $key)
            ->exists();

        if ($exists) {
            $connection->table('plugin_settings')
                ->where('plugin_name', $plugin)
                ->where('key', $key)
                ->update([
                    'value' => $stored,
                    'is_secret' => $isSecret,
                    'updated_by' => $updatedByEmployeeId,
                    'updated_at' => $now,
                ]);

            return;
        }

        $connection->table('plugin_settings')->insert([
            'plugin_name' => $plugin,
            'key' => $key,
            'value' => $stored,
            'is_secret' => $isSecret,
            'updated_by' => $updatedByEmployeeId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function get(string $plugin, string $key): ?string
    {
        $row = DB::connection($this->connections->tenantConnectionName())
            ->table('plugin_settings')
            ->where('plugin_name', $plugin)
            ->where('key', $key)
            ->first();

        if ($row === null || $row->value === null) {
            return null;
        }

        return $row->is_secret ? Crypt::decryptString((string) $row->value) : (string) $row->value;
    }
}
