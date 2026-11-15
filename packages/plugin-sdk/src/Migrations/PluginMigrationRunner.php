<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Migrations;

use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\PluginSdk\Exceptions\PluginMigrationRefused;
use Flexipos\PluginSdk\Manifest\PluginName;
use Flexipos\PluginSdk\Support\Connections;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Runs a plugin's own migrations against the tenant connection the caller
 * has already entered (T-019 AC-38, AC-39, plan Ruling 6).
 *
 * Every file is checked by {@see PluginMigrationRules} **before any of
 * them runs** - a refused set changes nothing. The caller is expected to
 * have entered the tenant for the migrate credential already; this class
 * never names that purpose itself.
 */
final class PluginMigrationRunner
{
    public function __construct(
        private readonly Connections $connections,
        private readonly RunnerClock $clock,
    ) {}

    public function run(string $pluginName, string $directory): int
    {
        $prefix = PluginName::tablePrefix($pluginName);
        $files = glob(rtrim($directory, '/\\').'/*.php') ?: [];
        sort($files);

        $violationsByFile = [];

        foreach ($files as $file) {
            $found = PluginMigrationRules::inspect((string) file_get_contents($file), $prefix);

            if ($found !== []) {
                $violationsByFile[basename($file)] = $found;
            }
        }

        if ($violationsByFile !== []) {
            throw PluginMigrationRefused::violatesRules($pluginName, $violationsByFile);
        }

        $connectionName = $this->connections->tenantConnectionName();
        $connection = DB::connection($connectionName);

        $already = $connection->table('plugin_migrations')->where('plugin_name', $pluginName)->pluck('migration')->all();

        $batch = null;

        foreach ($files as $file) {
            $name = basename($file, '.php');

            if (in_array($name, $already, true)) {
                continue;
            }

            if ($batch === null) {
                $maxBatch = (int) $connection->table('plugin_migrations')->where('plugin_name', $pluginName)->max('batch');
                $batch = $maxBatch + 1;
            }

            /** @var Migration $migration */
            $migration = require $file;

            DB::usingConnection($connectionName, function () use ($migration): void {
                // Laravel's own Migration base class declares no up(): every
                // migration file supplies its own, which is why this is a
                // dynamic call rather than a typed one.
                call_user_func([$migration, 'up']);
            });

            $connection->table('plugin_migrations')->insert([
                'plugin_name' => $pluginName,
                'migration' => $name,
                'batch' => $batch,
                'ran_at' => $this->clock->timestamp(),
            ]);
        }

        return $connection->table('plugin_migrations')->where('plugin_name', $pluginName)->count();
    }
}
