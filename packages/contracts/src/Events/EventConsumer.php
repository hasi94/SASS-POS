<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Events;

/**
 * One named consumer group reading the shared event stream (T-018 Ruling 2
 * and 3, AC-21 to AC-24).
 *
 * **`handle()` must be idempotent by `$event->id`.** Processing the same
 * event identifier twice - a redelivery after a crash between acting and
 * acknowledging - must produce the same end state as processing it once,
 * because this method checks before acting; the transport is at-least-once
 * and never relied on for exactly-once delivery (AC-23).
 */
interface EventConsumer
{
    /**
     * The consumer group name this consumer reads as. Every group sees
     * every event on the shared stream (Ruling 3); a type this consumer does
     * not list in {@see self::eventTypes()} is acknowledged as a no-op.
     */
    public function group(): string;

    /**
     * @return list<string>
     */
    public function eventTypes(): array;

    public function handle(EventEnvelope $event): void;
}
