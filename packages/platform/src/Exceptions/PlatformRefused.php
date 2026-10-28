<?php

declare(strict_types=1);

namespace Flexipos\Platform\Exceptions;

use RuntimeException;

/**
 * The one exception this package throws when it refuses to do something that
 * is not a plan-limit refusal (that is `Flexipos\Contracts\Platform\PlanLimitReached`).
 *
 * Closed reasons only: `validation`, `not_found`, `not_current`. Never
 * constructed without one.
 */
final class PlatformRefused extends RuntimeException
{
    private function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function validation(string $message): self
    {
        return new self('validation', $message);
    }

    public static function notFound(string $message): self
    {
        return new self('not_found', $message);
    }

    public static function notCurrent(string $message): self
    {
        return new self('not_current', $message);
    }
}
