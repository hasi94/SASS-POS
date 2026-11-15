<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Plugins;

/**
 * What a handler receives, naming the point and which plugin is being
 * invoked (T-019 plan Ruling 1).
 */
final readonly class HookCall
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $point,
        public string $plugin,
        public array $payload,
    ) {}
}
