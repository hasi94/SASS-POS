<?php

declare(strict_types=1);

namespace Flexipos\PluginSdk\Manifest;

use Flexipos\Contracts\Plugins\HookMode;

/**
 * One validated `hooks[]` entry of a manifest (T-019 plan Ruling 2).
 */
final readonly class ManifestHook
{
    public function __construct(
        public string $point,
        public HookMode $mode,
        public string $handler,
    ) {}
}
