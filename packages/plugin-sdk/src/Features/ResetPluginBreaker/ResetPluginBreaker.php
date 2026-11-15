<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Features\ResetPluginBreaker;

final class ResetPluginBreaker
{
    public function __construct(
        public readonly string $pluginName,
        public readonly string $tenantId,
    ) {}
}
