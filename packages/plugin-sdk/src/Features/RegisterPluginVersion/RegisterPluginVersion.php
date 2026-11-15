<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Features\RegisterPluginVersion;

final class RegisterPluginVersion
{
    public function __construct(
        public readonly string $packagePath,
    ) {}
}
