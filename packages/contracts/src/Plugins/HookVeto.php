<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Plugins;

/**
 * Which plugin vetoed a dispatch, and why (T-019 AC-23).
 */
final readonly class HookVeto
{
    public function __construct(
        public string $plugin,
        public string $reason,
    ) {}
}
