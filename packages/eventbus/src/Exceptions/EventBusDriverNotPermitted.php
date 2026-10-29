<?php

declare(strict_types=1);

namespace Flexipos\Eventbus\Exceptions;

use Flexipos\Eventbus\Bus\EventBusDriver;
use Flexipos\Kernel\Tenancy\Secrets\EnvSecretResolver;
use RuntimeException;

/**
 * `eventbus.driver` named a driver this process may not use right now
 * (T-018 Ruling 8, AC-1, AC-4).
 */
final class EventBusDriverNotPermitted extends RuntimeException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function unknown(string $driver): self
    {
        return new self(sprintf(
            'Unknown event bus driver [%s]. Configure eventbus.driver as one of [%s].',
            $driver,
            implode(', ', [EventBusDriver::MEMORY, EventBusDriver::REDIS])
        ));
    }

    /**
     * A process-local bus in production would silently lose every event the
     * moment this process exits - exactly what AC-4 forbids.
     */
    public static function standInOutsideLocal(string $environment): self
    {
        return new self(sprintf(
            'The in-memory event bus stand-in is for local development only and is refused in the [%s] '.
            'environment; it is permitted only in [%s]. A process-local bus in production would silently '.
            'lose every event.',
            $environment,
            implode(', ', EnvSecretResolver::LOCAL_ENVIRONMENTS)
        ));
    }
}
