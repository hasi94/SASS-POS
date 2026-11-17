<?php

declare(strict_types=1);

namespace Flexipos\Finance\Features\ReverseJournalEntry;

/**
 * Reverse one posted journal entry (T-033 AC-70).
 *
 * Every value is `mixed` and validated by the service, which parses the whole
 * request in one fixed order.
 */
final class ReverseJournalEntry
{
    public function __construct(
        public readonly mixed $entryId,
        public readonly mixed $entryDate,
        public readonly mixed $reason,
        public readonly ?string $actorId = null,
    ) {}
}
