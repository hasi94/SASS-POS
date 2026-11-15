<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Features\EnablePlugin;

use Flexipos\Contracts\Platform\PluginLicences;
use Flexipos\Contracts\Plugins\HookMode;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\PluginSdk\Exceptions\PluginRefused;
use Flexipos\PluginSdk\Features\MigratePlugin\MigratePluginHandler;
use Flexipos\PluginSdk\Manifest\Manifest;
use Flexipos\PluginSdk\Manifest\ManifestValidator;
use Flexipos\PluginSdk\Manifest\VersionConstraint;
use Flexipos\PluginSdk\Support\Connections;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use Throwable;

/**
 * `plugin:enable {plugin} {--tenant=} {--plugin-version=} {--as=}` (T-019
 * AC-9, AC-11, AC-13 to AC-16, AC-20, AC-25, plan Ruling 8).
 *
 * **Every refusal below happens before any write.** The option is
 * `--plugin-version` because Symfony reserves `--version`.
 */
final class EnablePluginHandler
{
    public function __construct(
        private readonly Connections $connections,
        private readonly RunnerClock $clock,
        private readonly ManifestValidator $validator,
        private readonly PluginLicences $licences,
        private readonly MigratePluginHandler $migratePlugin,
    ) {}

    public function handle(EnablePlugin $command): EnablePluginResult
    {
        $master = $this->connections->master();

        $plugin = $master->table('plugins')->where('name', $command->pluginName)->whereNull('deleted_at')->first();

        if ($plugin === null) {
            throw PluginRefused::unknownPlugin($command->pluginName);
        }

        $version = $this->resolveVersion($master, $plugin, $command->pluginVersion);

        $tenant = $master->table('tenants')->where('id', $command->tenantId)->first();

        if ($tenant === null) {
            throw PluginRefused::unknownTenant($command->tenantId);
        }

        $existing = $master->table('tenant_plugins')
            ->where('tenant_id', $command->tenantId)
            ->where('plugin_id', $plugin->id)
            ->first();

        if ($existing !== null) {
            if ((string) $existing->status === 'enabled') {
                return new EnablePluginResult((string) $existing->id, 'enabled', (int) $existing->schema_version, true);
            }

            if ((string) $existing->status === 'error') {
                throw PluginRefused::tripped($command->pluginName);
            }

            if ((string) $existing->status === 'updating') {
                throw PluginRefused::updating($command->pluginName);
            }

            if ((string) $existing->plugin_version_id !== (string) $version->id) {
                throw PluginRefused::versionChangeNotSupported($command->pluginName);
            }
        }

        if ((string) $plugin->visibility === 'private') {
            $granted = $master->table('plugin_tenant_access')
                ->where('plugin_id', $plugin->id)
                ->where('tenant_id', $command->tenantId)
                ->exists();

            if (! $granted) {
                throw PluginRefused::accessNotGranted($command->pluginName, $command->tenantId);
            }
        }

        $manifest = $this->validator->validate((string) $version->manifest);

        foreach ($master->table('plugin_dependencies')->where('plugin_version_id', $version->id)->get() as $dependency) {
            if (! $this->dependencySatisfied($master, $command->tenantId, $dependency)) {
                throw PluginRefused::dependencyNotSatisfied(
                    (string) $dependency->depends_on_plugin,
                    (string) $dependency->version_constraint
                );
            }
        }

        $this->assertNoOverrideClash($master, $command->tenantId, (string) $plugin->id, $manifest);

        $licenceId = null;

        if ($manifest->requiresLicence) {
            $licenceId = $this->licences->currentLicenceId($command->tenantId, $manifest->name);

            if ($licenceId === null) {
                throw PluginRefused::licenceRequired($command->pluginName);
            }
        }

        $rowId = $this->writeInstalling($master, $existing, (string) $plugin->id, (string) $version->id, $command, $licenceId);

        try {
            $schemaVersion = $this->migratePlugin->migrateOneTenant($command->pluginName, $command->tenantId, (string) $version->id);
        } catch (Throwable $exception) {
            $master->table('tenant_plugins')->where('id', $rowId)->update([
                'last_error' => $exception->getMessage(),
                'updated_at' => $this->clock->timestamp(),
            ]);

            throw $exception;
        }

        $master->table('tenant_plugins')->where('id', $rowId)->update([
            'status' => 'enabled',
            'enabled_at' => $this->clock->timestamp(),
            'last_error' => null,
            'schema_version' => $schemaVersion,
            'updated_at' => $this->clock->timestamp(),
        ]);

        return new EnablePluginResult($rowId, 'enabled', $schemaVersion, false);
    }

    private function resolveVersion(Connection $master, object $plugin, ?string $requestedVersion): object
    {
        if ($requestedVersion === null) {
            if ($plugin->latest_version_id === null) {
                throw PluginRefused::unknownVersion((string) $plugin->name, '(none registered)');
            }

            $version = $master->table('plugin_versions')->where('id', $plugin->latest_version_id)->first();
        } else {
            $version = $master->table('plugin_versions')
                ->where('plugin_id', $plugin->id)
                ->where('version', $requestedVersion)
                ->first();

            if ($version === null) {
                throw PluginRefused::unknownVersion((string) $plugin->name, $requestedVersion);
            }
        }

        if ((string) $version->status !== 'available') {
            throw PluginRefused::versionNotAvailable((string) $plugin->name, (string) $version->version);
        }

        return $version;
    }

    private function dependencySatisfied(Connection $master, string $tenantId, object $dependency): bool
    {
        $row = $master->table('tenant_plugins')
            ->join('plugins', 'tenant_plugins.plugin_id', '=', 'plugins.id')
            ->join('plugin_versions', 'tenant_plugins.plugin_version_id', '=', 'plugin_versions.id')
            ->where('tenant_plugins.tenant_id', $tenantId)
            ->where('tenant_plugins.status', 'enabled')
            ->where('plugins.name', (string) $dependency->depends_on_plugin)
            ->select('plugin_versions.version as version')
            ->first();

        if ($row === null) {
            return false;
        }

        try {
            return VersionConstraint::parse((string) $dependency->version_constraint)->satisfiedBy((string) $row->version);
        } catch (Throwable) {
            return false;
        }
    }

    private function assertNoOverrideClash(Connection $master, string $tenantId, string $pluginId, Manifest $manifest): void
    {
        $overridePoints = [];

        foreach ($manifest->hooks as $hook) {
            if ($hook->mode === HookMode::Override) {
                $overridePoints[] = $hook->point;
            }
        }

        if ($overridePoints === []) {
            return;
        }

        $others = $master->table('tenant_plugins')
            ->join('plugins', 'tenant_plugins.plugin_id', '=', 'plugins.id')
            ->join('plugin_versions', 'tenant_plugins.plugin_version_id', '=', 'plugin_versions.id')
            ->where('tenant_plugins.tenant_id', $tenantId)
            ->where('tenant_plugins.status', 'enabled')
            ->where('tenant_plugins.plugin_id', '!=', $pluginId)
            ->select(['plugins.name as plugin_name', 'plugin_versions.manifest as manifest'])
            ->get();

        foreach ($others as $other) {
            /** @var array<string, mixed> $otherManifest */
            $otherManifest = json_decode((string) $other->manifest, true) ?: [];

            foreach ((array) ($otherManifest['hooks'] ?? []) as $hook) {
                $hook = (array) $hook;

                if (($hook['mode'] ?? null) === 'override' && in_array($hook['point'] ?? null, $overridePoints, true)) {
                    throw PluginRefused::overrideAlreadyClaimed((string) $hook['point'], (string) $other->plugin_name);
                }
            }
        }
    }

    private function writeInstalling(Connection $master, ?object $existing, string $pluginId, string $versionId, EnablePlugin $command, ?string $licenceId): string
    {
        $now = $this->clock->timestamp();
        $rowId = $existing !== null ? (string) $existing->id : (string) Str::ulid();

        $attributes = [
            'plugin_version_id' => $versionId,
            'status' => 'installing',
            'licence_id' => $licenceId,
            'installed_at' => $now,
            'installed_by' => $command->installedBy,
            'failure_count' => 0,
            'updated_at' => $now,
        ];

        if ($existing === null) {
            $master->table('tenant_plugins')->insert(array_merge($attributes, [
                'id' => $rowId,
                'tenant_id' => $command->tenantId,
                'plugin_id' => $pluginId,
                'schema_version' => 0,
                'enabled_at' => null,
                'disabled_at' => null,
                'tripped_at' => null,
                'last_error' => null,
                'created_at' => $now,
            ]));
        } else {
            $master->table('tenant_plugins')->where('id', $rowId)->update($attributes);
        }

        return $rowId;
    }
}
