<?php

declare(strict_types=1);

namespace Flexipos\Eventbus\Exceptions;

use RuntimeException;
use Throwable;

/**
 * The transport could not be reached - resolving the connection, or running
 * a command on it, both end up here (T-018 AC-4).
 *
 * **Named, loud, and never a fallback.** Every `EventBus` method throws this
 * instead of silently degrading to the in-memory stand-in and instead of
 * silently dropping the event - the same "it fails, it never skips"
 * convention the MySQL engine harness already uses for the other kind of
 * unreachable dependency this platform has.
 */
final class EventBusUnreachable extends RuntimeException
{
    private function __construct(string $message, Throwable $previous)
    {
        parent::__construct($message, 0, $previous);
    }

    public static function forConnection(string $connection, string $operation, Throwable $previous): self
    {
        return new self(
            "The event bus connection [{$connection}] could not be reached while running [{$operation}]: {$previous->getMessage()}",
            $previous
        );
    }
}
