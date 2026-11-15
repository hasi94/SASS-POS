<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Features\RegisterPluginVersion;

use Flexipos\Kernel\Schema\Support\RunnerClock;
use Flexipos\PluginSdk\Exceptions\PluginRefused;
use Flexipos\PluginSdk\Manifest\Manifest;
use Flexipos\PluginSdk\Manifest\ManifestValidator;
use Flexipos\PluginSdk\Support\Connections;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;

/**
 * `plugin:register {path}` (T-019 AC-8, AC-10, AC-11 data; plan Ruling 7).
 *
 * Validates fully, with every refusal happening before any write (AC-10's
 * core-version refusal happens here); `versionAlreadyRegistered` is
 * checked before the transaction even opens, so the unique key is a
 * backstop and never the normal path.
 */
final class RegisterPluginVersionHandler
{
    public function __construct(
        private readonly ManifestValidator $validator,
        private readonly Connections $connections,
        private readonly RunnerClock $clock,
    ) {}

    public function handle(RegisterPluginVersion $command): RegisterPluginVersionResult
    {
        $manifestPath = rtrim($command->packagePath, '/\\').'/plugin.json';
        $json = (string) file_get_contents($manifestPath);

        $manifest = $this->validator->validate($json);

        $checksum = hash('sha256', $json);
        $migrationCount = count(glob(rtrim($command->packagePath, '/\\').'/'.$manifest->migrationsDir.'/*.php') ?: []);

        $connection = $this->connections->master();

        $existingPlugin = $connection->table('plugins')->where('name', $manifest->name)->first();

        if ($existingPlugin !== null) {
            $alreadyRegistered = $connection->table('plugin_versions')
                ->where('plugin_id', $existingPlugin->id)
                ->where('version', $manifest->version)
                ->exists();

            if ($alreadyRegistered) {
                throw PluginRefused::versionAlreadyRegistered($manifest->name, $manifest->version);
            }
        }

        return $connection->transaction(function () use ($connection, $command, $manifest, $json, $checksum, $migrationCount, $existingPlugin): RegisterPluginVersionResult {
            $now = $this->clock->timestamp();

            $pluginId = $existingPlugin !== null ? (string) $existingPlugin->id : (string) Str::ulid();

            if ($existingPlugin === null) {
                $connection->table('plugins')->insert([
                    'id' => $pluginId,
                    'name' => $manifest->name,
                    'display_name' => $manifest->displayName,
                    'type' => $manifest->type,
                    'author' => $manifest->author,
                    'description' => $manifest->description,
                    'visibility' => $manifest->visibility,
                    'status' => 'published',
                    'latest_version_id' => null,
                    'signature_key_id' => null,
                    'requires_licence' => $manifest->requiresLicence,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'deleted_at' => null,
                ]);
            } else {
                $connection->table('plugins')->where('id', $pluginId)->update([
                    'display_name' => $manifest->displayName,
                    'type' => $manifest->type,
                    'visibility' => $manifest->visibility,
                    'author' => $manifest->author,
                    'description' => $manifest->description,
                    'requires_licence' => $manifest->requiresLicence,
                    'updated_at' => $now,
                ]);
            }

            $versionId = (string) Str::ulid();

            $connection->table('plugin_versions')->insert([
                'id' => $versionId,
                'plugin_id' => $pluginId,
                'version' => $manifest->version,
                'core_constraint' => $manifest->coreConstraint,
                'manifest' => $json,
                'package_path' => $command->packagePath,
                'checksum' => $checksum,
                'signature' => null,
                'migration_count' => $migrationCount,
                'status' => 'available',
                'released_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach ($manifest->requires as $dependsOnPlugin => $constraint) {
                $connection->table('plugin_dependencies')->insert([
                    'plugin_version_id' => $versionId,
                    'depends_on_plugin' => $dependsOnPlugin,
                    'version_constraint' => $constraint,
                ]);
            }

            $this->moveLatestVersionIfHigher($connection, $pluginId, $versionId, $manifest);

            return new RegisterPluginVersionResult($pluginId, $versionId, $manifest->name, $manifest->version);
        });
    }

    private function moveLatestVersionIfHigher(Connection $connection, string $pluginId, string $versionId, Manifest $manifest): void
    {
        $plugin = $connection->table('plugins')->where('id', $pluginId)->first();

        $currentLatestVersion = null;

        if ($plugin !== null && $plugin->latest_version_id !== null) {
            $latest = $connection->table('plugin_versions')->where('id', $plugin->latest_version_id)->first();
            $currentLatestVersion = $latest?->version;
        }

        if ($currentLatestVersion === null || version_compare($manifest->version, (string) $currentLatestVersion, '>')) {
            $connection->table('plugins')->where('id', $pluginId)->update(['latest_version_id' => $versionId]);
        }
    }
}
