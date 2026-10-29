<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Plugins;

/**
 * One `add` handler's contribution, attributed to the plugin that produced
 * it (T-019 AC-22).
 */
final readonly class HookContribution
{
    public function __construct(
        public string $plugin,
        public mixed $value,
    ) {}
}
