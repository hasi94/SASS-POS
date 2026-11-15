<?php

declare(strict_types=1);

namespace Flexipos\Eventbus\Tests\Support;

use Flexipos\Contracts\Events\EventEnvelope;
use Flexipos\Eventbus\Bus\Delivery;
use Flexipos\Eventbus\Contracts\EventBus;
use Flexipos\Eventbus\Exceptions\EventBusUnreachable;
use RuntimeException;

/**
 * Decorates the stand-in and throws {@see EventBusUnreachable} on the next
 * N calls to `acknowledge()`, or forever (T-018 review round 1, W-2): the
 * fixture a test needs to prove a failed acknowledge() after a successful
 * `handle()` is not treated as the consumer's own failure.
 *
 * Every other method delegates straight through: this fixture is about the
 * acknowledge-failure path only.
 */
final class FailingAcknowledgeEventBus implements EventBus
{
    private int $remaining;

    public function __construct(
        private readonly EventBus $inner,
        int $failNextAcknowledges = PHP_INT_MAX,
    ) {
        $this->remaining = $failNextAcknowledges;
    }

    public function publish(string $stream, EventEnvelope $event): string
    {
        return $this->inner->publish($stream, $event);
    }

    public function ensureGroup(string $stream, string $group): void
    {
        $this->inner->ensureGroup($stream, $group);
    }

    public function read(string $stream, string $group, string $consumer, int $count): array
    {
        return $this->inner->read($stream, $group, $consumer, $count);
    }

    public function acknowledge(string $stream, string $group, string $messageId): void
    {
        if ($this->remaining > 0) {
            $this->remaining--;

            throw EventBusUnreachable::forConnection(
                'fixture',
                'acknowledge',
                new RuntimeException('FailingAcknowledgeEventBus: simulated failure.')
            );
        }

        $this->inner->acknowledge($stream, $group, $messageId);
    }

    public function pending(string $stream, string $group, int $count): array
    {
        return $this->inner->pending($stream, $group, $count);
    }

    public function claim(string $stream, string $group, string $consumer, array $ids, int $minIdleMs): array
    {
        return $this->inner->claim($stream, $group, $consumer, $ids, $minIdleMs);
    }

    public function deadLetter(string $deadStream, Delivery $delivery, string $group, string $reason): string
    {
        return $this->inner->deadLetter($deadStream, $delivery, $group, $reason);
    }

    public function deadLetters(string $deadStream, int $count, bool $newestFirst): array
    {
        return $this->inner->deadLetters($deadStream, $count, $newestFirst);
    }

    public function replay(string $deadStream, string $deadId): string
    {
        return $this->inner->replay($deadStream, $deadId);
    }
}
