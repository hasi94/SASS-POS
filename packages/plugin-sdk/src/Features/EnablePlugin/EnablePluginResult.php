<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Features\EnablePlugin;

final class EnablePluginResult
{
    public function __construct(
        public readonly string $tenantPluginId,
        public readonly string $status,
        public readonly int $schemaVersion,
        public readonly bool $noop,
    ) {}
}
