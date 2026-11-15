<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Platform;

use RuntimeException;

/**
 * A plan limit refuses a creation or a reactivation (T-016 AC-1, AC-2).
 *
 * Carries the key and the numeric limit the caller was refused at, so the host
 * renderer (Ruling 11) can answer `{"error":"plan_limit_reached","limit_key":
 * "<key>","limit":<n>}` without re-deriving either from anything else.
 */
final class PlanLimitReached extends RuntimeException
{
    private function __construct(
        private readonly LimitKey $limitKey,
        private readonly int $limit,
    ) {
        parent::__construct("The plan limit for [{$limitKey->value}] ({$limit}) has been reached.");
    }

    public static function for(LimitKey $key, int $limit): self
    {
        return new self($key, $limit);
    }

    public function limitKey(): LimitKey
    {
        return $this->limitKey;
    }

    public function limit(): int
    {
        return $this->limit;
    }
}
