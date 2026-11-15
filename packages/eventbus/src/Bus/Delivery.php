<?php

declare(strict_types=1);

namespace Flexipos\Eventbus\Bus;

use Flexipos\Contracts\Events\EventEnvelope;

/**
 * One message delivered to one consumer, by `read()` or `claim()` (T-018
 * Ruling 3).
 *
 * `$envelope` is null when the wire fields could not be decoded - a poison
 * message, never a thrown exception (AC-27's "malformed envelope" case).
 * `$rawFields` is kept regardless, so a dead letter can still carry whatever
 * bytes were actually on the stream.
 */
final readonly class Delivery
{
    /**
     * @param  array<string, string>  $rawFields
     */
    public function __construct(
        public string $messageId,
        public string $stream,
        public ?EventEnvelope $envelope,
        public array $rawFields,
        public int $deliveryCount,
    ) {}
}
