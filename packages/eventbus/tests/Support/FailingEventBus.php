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
 * N publishes, or forever (T-018 step 6: AC-16, AC-17, AC-4 through the
 * relay).
 *
 * Every other method delegates straight through: this fixture is about the
 * relay's publish-failure path only, never about the consumer side.
 */
final class FailingEventBus implements EventBus
{
    private int $remaining;

    public function __construct(
        private readonly EventBus $inner,
        int $failNextPublishes = PHP_INT_MAX,
    ) {
        $this->remaining = $failNextPublishes;
    }

    public function heal(): void
    {
        $this->remaining = 0;
    }

    public function publish(string $stream, EventEnvelope $event): string
    {
        if ($this->remaining > 0) {
            $this->remaining--;

            throw EventBusUnreachable::forConnection(
                'fixture',
                'publish',
                new RuntimeException('FailingEventBus: simulated failure.')
            );
        }

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
