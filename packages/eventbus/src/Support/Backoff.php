<?php

declare(strict_types=1);

namespace Flexipos\Eventbus\Support;

/**
 * The one exponential back-off formula both the relay and the consumer
 * runner share (T-018 Ruling 6 and 7, D-8).
 *
 * `delay(n) = min(base * 2^(n-1), cap)` - no literal in either caller, so a
 * configured base or cap moves both the relay's publish retries and the
 * consumer's reclaim schedule the same way.
 */
final class Backoff
{
    public static function delaySeconds(int $attempt, int $base, int $cap): int
    {
        $attempt = max(1, $attempt);

        $delay = $base * (2 ** ($attempt - 1));

        return (int) min($delay, $cap);
    }
}
