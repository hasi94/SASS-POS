<?php

declare(strict_types=1);

namespace Flexipos\Eventbus\Features\ListDeadLetters;

/**
 * List the dead-letter stream's current entries (T-018 Ruling 10, AC-30).
 *
 * Fleet-wide, not per tenant: the dead-letter stream is shared by every
 * shop, the same topology as the event stream itself (Ruling 3).
 */
final readonly class ListDeadLetters
{
    public function __construct(
        public bool $newestFirst = false,
        public ?int $limit = null,
    ) {}
}
