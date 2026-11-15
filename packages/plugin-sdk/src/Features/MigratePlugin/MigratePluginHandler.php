<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Features\MigratePlugin;

use Flexipos\Kernel\Tenancy\Tenancy;
use Flexipos\Kernel\Tenancy\TenantConnectionPurpose;
use Flexipos\PluginSdk\Exceptions\PluginRefused;
use Flexipos\PluginSdk\Migrations\PluginMigrationRunner;
use Flexipos\PluginSdk\Support\Connections;
use Illuminate\Database\Connection;
use Throwable;

/**
 * `plugin:migrate {plugin} {--tenant=*}` (T-019 AC-38, AC-39, `docs/database.md`
 * §9.6, plan Ruling 6).
 *
 * The kernel's own `tenant:migrate` cannot carry `--plugin`, because the
 * kernel may not depend on `plugin-sdk` - this is the command that §9.6
 * describes instead, with the same behaviour. **One of the two files in
 * this package allowed to name `TenantConnectionPurpose`** (plan section
 * 5, step 15): plugin DDL runs under the migrate credential, exactly as
 * the kernel's own runner does.
 */
final class MigratePluginHandler
{
    public function __construct(
        private readonly Connections $connections,
        private readonly Tenancy $tenancy,
        private readonly PluginMigrationRunner $runner,
    ) {}

    public function handle(MigratePlugin $command): MigratePluginResult
    {
        $master = $this->connections->master();

        $plugin = $master->table('plugins')->where('name', $command->pluginName)->first();

        if ($plugin === null) {
            throw PluginRefused::unknownPlugin($command->pluginName);
        }

        $query = $master->table('tenant_plugins')
            ->where('plugin_id', $plugin->id)
            ->where('status', 'enabled');

        if ($command->tenantIds !== null) {
            $query->whereIn('tenant_id', $command->tenantIds);
        }

        $applied = [];
        $failed = [];

        foreach ($query->get() as $row) {
            $tenantId = (string) $row->tenant_id;

            try {
                $count = $this->migrateOneTenant($command->pluginName, $tenantId, (string) $row->plugin_version_id);

                $master->table('tenant_plugins')->where('id', $row->id)->update(['schema_version' => $count]);

                $applied[$tenantId] = $count;
            } catch (Throwable $exception) {
                $failed[$tenantId] = $exception->getMessage();
            }
        }

        return new MigratePluginResult($applied, $failed);
    }

    /**
     * Run one plugin's migrations against one tenant, returning the
     * applied count (AC-38's `schema_version`).
     *
     * **The one method `EnablePluginHandler` calls instead of naming
     * `TenantConnectionPurpose` itself** (plan section 5, step 15): it
     * runs the migration for a tenant whose row is still `installing`,
     * which is why it never filters by status the way {@see self::handle()}
     * does.
     */
    public function migrateOneTenant(string $pluginName, string $tenantId, string $pluginVersionId): int
    {
        $migrationsDir = $this->migrationsDirectoryFor($this->connections->master(), $pluginVersionId);

        return $this->tenancy->run($tenantId, function () use ($pluginName, $migrationsDir): int {
            return $this->runner->run($pluginName, $migrationsDir);
        }, TenantConnectionPurpose::Migrating);
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
