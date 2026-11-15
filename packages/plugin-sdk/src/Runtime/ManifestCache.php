<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Runtime;

use Flexipos\PluginSdk\Manifest\Manifest;
use Flexipos\PluginSdk\Manifest\ManifestValidator;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The validated {@see Manifest} for one immutable `plugin_versions` row,
 * keyed by `plugin_version_id` (T-019 AC-35, AC-37, plan Ruling 10).
 *
 * A singleton for the process: the version a row names never changes, so
 * caching by that id is safe, and a failure is cached too - "the failure
 * is cached too, so a broken version does not log on every request."
 */
final class ManifestCache
{
    /**
     * @var array<string, Manifest|false>
     */
    private array $cache = [];

    public function __construct(
        private readonly ManifestValidator $validator,
        private readonly LoggerInterface $logger,
    ) {}

    public function get(string $pluginVersionId, string $rawManifest, string $pluginName): ?Manifest
    {
        if (array_key_exists($pluginVersionId, $this->cache)) {
            $cached = $this->cache[$pluginVersionId];

            return $cached === false ? null : $cached;
        }

        try {
            $manifest = $this->validator->validate($rawManifest);
            $this->cache[$pluginVersionId] = $manifest;

            return $manifest;
        } catch (Throwable $exception) {
            $this->cache[$pluginVersionId] = false;

            $this->logger->error('A plugin manifest failed re-validation at load; its hooks are not registered.', [
                'plugin' => $pluginName,
                'plugin_version_id' => $pluginVersionId,
                'reason' => $exception->getMessage(),
            ]);

            return null;
        }
    }
}
