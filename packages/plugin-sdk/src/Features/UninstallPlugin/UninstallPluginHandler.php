<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Features\UninstallPlugin;

use Flexipos\Kernel\Schema\Support\SourceRules;
use Flexipos\Kernel\Tenancy\Tenancy;
use Flexipos\Kernel\Tenancy\TenantConnectionPurpose;
use Flexipos\PluginSdk\Exceptions\PluginRefused;
use Flexipos\PluginSdk\Manifest\PluginName;
use Flexipos\PluginSdk\Support\Connections;
use Flexipos\PluginSdk\Support\PluginDataExporter;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * `plugin:uninstall {plugin} {--tenant=}` (T-019 AC-20; plan Ruling 8).
 *
 * **The second of the two files in this package allowed to name
 * `TenantConnectionPurpose`** (plan section 5, step 15).
 *
 * A plugin's own tables are never found by prefix match - they are read
 * from its migration files ({@see SourceRules::tablesCreatedIn()}), so
 * uninstalling `fixture-echo` can never touch `fixture-echo-two`'s tables
 * even though one prefix begins the other.
 */
final class UninstallPluginHandler
{
    public function __construct(
        private readonly Connections $connections,
        private readonly Tenancy $tenancy,
        private readonly PluginDataExporter $exporter,
    ) {}

    public function handle(UninstallPlugin $command): UninstallPluginResult
    {
        $master = $this->connections->master();

        $plugin = $master->table('plugins')->where('name', $command->pluginName)->first();

        if ($plugin === null) {
            throw PluginRefused::unknownPlugin($command->pluginName);
        }

        $row = $master->table('tenant_plugins')
            ->where('tenant_id', $command->tenantId)
            ->where('plugin_id', $plugin->id)
            ->first();

        if ($row === null) {
            throw PluginRefused::unknownPlugin($command->pluginName);
        }

        if ((string) $row->status === 'enabled') {
            throw PluginRefused::disableFirst($command->pluginName);
        }

        $migrationsDir = $this->migrationsDirectoryFor($master, (string) $row->plugin_version_id);
        $tables = $this->ownedTables($migrationsDir);
        $prefix = PluginName::tablePrefix($command->pluginName);

        $dropped = $this->tenancy->run($command->tenantId, function () use ($command, $tables, $prefix): array {
            $connectionName = $this->connections->tenantConnectionName();
            $connection = DB::connection($connectionName);
            $schema = $connection->getSchemaBuilder();

            $existingTables = array_values(array_filter($tables, fn (string $table): bool => $schema->hasTable($table)));

            // Every export must succeed before anything is dropped.
            foreach ($existingTables as $table) {
                $this->exporter->export($command->tenantId, $command->pluginName, $table, $connection->table($table)->cursor());
            }

            foreach ($existingTables as $table) {
                if (! str_starts_with($table, $prefix)) {
                    continue;
                }

                $schema->drop($table);
            }

            $connection->table('plugin_migrations')->where('plugin_name', $command->pluginName)->delete();

            return $existingTables;
        }, TenantConnectionPurpose::Migrating);

        $master->table('tenant_plugins')->where('id', $row->id)->delete();

        return new UninstallPluginResult($dropped);
    }

    /**
     * @return list<string>
     */
    private function ownedTables(string $migrationsDir): array
    {
        $tables = [];

        foreach (glob(rtrim($migrationsDir, '/\\').'/*.php') ?: [] as $file) {
            foreach (SourceRules::tablesCreatedIn((string) file_get_contents($file)) as $table) {
                $tables[] = $table;
            }
        }

        return array_values(array_unique($tables));
    }

    private function migrationsDirectoryFor(Connection $master, string $versionId): string
    {
        $version = $master->table('plugin_versions')->where('id', $versionId)->first();

        /** @var array<string, mixed> $manifest */
        $manifest = json_decode((string) $version?->manifest, true) ?: [];
        $migrationsDir = isset($manifest['migrations']) ? (string) $manifest['migrations'] : 'migrations';

        return rtrim((string) $version?->package_path, '/\\').'/'.$migrationsDir;
    }
}
