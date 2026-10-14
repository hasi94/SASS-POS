<?php

declare(strict_types=1);

namespace Flexipos\Testing\Engine;

use RuntimeException;
use Throwable;

/**
 * The engine the environment names could not be reached.
 *
 * Thrown, never skipped (AC-63): a skipped engine test is an engine test nobody
 * runs, and the whole point of this suite is that the schema is checked against
 * a real server. The message names the engine, so the reader knows which
 * service to start rather than which test to ignore.
 */
final class EngineUnreachable extends RuntimeException
{
    public static function at(string $driver, string $host, string $port, string $database, Throwable $previous): self
    {
        return new self(sprintf(
            'The engine the environment names is unreachable: driver "%s" at %s:%s%s. '
            .'Engine tests fail rather than skip, so start the engine and run again. Underlying error: %s',
            $driver,
            $host,
            $port,
            $database === '' ? '' : ', database "'.$database.'"',
            $previous->getMessage()
        ), 0, $previous);
    }
}
