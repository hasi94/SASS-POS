<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Features\RegisterPluginVersion;

final class RegisterPluginVersionResult
{
    public function __construct(
        public readonly string $pluginId,
        public readonly string $versionId,
        public readonly string $name,
        public readonly string $version,
    ) {}
}
