<?php

declare(strict_types=1);

namespace Flexipos\Finance\Features\GetJournalEntry;

/**
 * Read one journal entry, by id or by its source key (T-033 AC-68).
 *
 * Name exactly one way: `entryId`, or `sourceType` with `sourceId` (and
 * `sourcePurpose` when the entry has one). Every value is `mixed`: the handler
 * validates, never a type declaration that would turn `1` into `"1"` before it
 * could be refused.
 */
final class GetJournalEntry
{
    public function __construct(
        public readonly mixed $entryId = null,
        public readonly mixed $sourceType = null,
        public readonly mixed $sourceId = null,
        public readonly mixed $sourcePurpose = null,
        public readonly ?string $actorId = null,
    ) {}
}
