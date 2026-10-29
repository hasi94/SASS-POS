<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Features\MigratePlugin;

final class MigratePluginResult
{
    /**
     * @param  array<string, int>  $applied  tenant id => schema_version reached
     * @param  array<string, string>  $failed  tenant id => error message
     */
    public function __construct(
        public readonly array $applied,
        public readonly array $failed,
    ) {}
}
