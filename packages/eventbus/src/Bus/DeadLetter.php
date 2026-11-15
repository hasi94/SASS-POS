<?php

declare(strict_types=1);

namespace Flexipos\Eventbus\Bus;

use Flexipos\Contracts\Events\EventEnvelope;

/**
 * One entry on the dead-letter stream (T-018 D-7, Ruling 3, AC-27, AC-30).
 *
 * The consumer-side failure state: the bus delivered the message, but no
 * consumer in the group ever finished it. Distinct from an `outbox_events`
 * row that stays `status='failed'` (AC-28, the relay-side failure: the bus
 * was never reachable at all).
 */
final readonly class DeadLetter
{
    public function __construct(
        public string $deadId,
        public ?string $eventId,
        public ?string $tenantId,
        public ?string $type,
        public string $originalStream,
        public ?string $originalMessageId,
        public string $group,
        public int $deliveryCount,
        public string $reason,
        public string $deadAt,
        public ?EventEnvelope $envelope,
    ) {}
}
