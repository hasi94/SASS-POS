<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Plugins;

/**
 * What an {@see AfterHook} receives, once the outbox event that recorded it
 * is consumed (T-019 plan Ruling 5 and 11).
 */
final readonly class AfterHookCall
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $plugin,
        public string $point,
        public array $payload,
        public mixed $result,
    ) {}
}
