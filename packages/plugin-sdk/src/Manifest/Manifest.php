<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Manifest;

/**
 * A validated `plugin.json` (T-019 plan Ruling 2, D-8).
 *
 * `migrationsDir` and every optional field already carry their default:
 * `ManifestValidator` is the one place a default is applied, so a
 * `Manifest` object is always fully resolved.
 */
final readonly class Manifest
{
    /**
     * @param  list<ManifestHook>  $hooks
     * @param  list<string>  $permissions
     * @param  array<string, string>  $requires  plugin name => version constraint
     */
    public function __construct(
        public string $name,
        public string $version,
        public string $coreConstraint,
        public array $hooks,
        public array $permissions,
        public bool $requiresLicence,
        public ?string $displayName,
        public string $type,
        public string $visibility,
        public ?string $author,
        public ?string $description,
        public array $requires,
        public string $migrationsDir,
    ) {}
}
