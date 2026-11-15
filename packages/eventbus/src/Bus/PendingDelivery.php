<?php

declare(strict_types=1);

namespace Flexipos\Eventbus\Bus;

/**
 * One entry on a consumer group's pending-entries list (T-018 Ruling 3,
 * AC-24): delivered, not yet acknowledged, with the stream's own idle time
 * and delivery count - never an application-level dedup table.
 */
final readonly class PendingDelivery
{
    public function __construct(
        public string $messageId,
        public string $stream,
        public string $consumer,
        public int $idleMs,
        public int $deliveryCount,
    ) {}
}
