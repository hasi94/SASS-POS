<?php

declare(strict_types=1);

namespace Flexipos\Finance\Journal;

/**
 * A reversal request after every pure check (T-033 Ruling 8, steps 1 and 2).
 *
 * Built only by {@see ReversalInput::parse}: the id is upper case, the date is
 * a real `Y-m-d`, the reason is trimmed and 1 to 500 characters.
 */
final readonly class ParsedReversal
{
    public function __construct(
        public string $entryId,
        public string $entryDate,
        public string $reason,
        public ?string $actorId,
    ) {}
}
