<?php

declare(strict_types=1);

namespace Flexipos\Finance\Features\ListJournalEntries;

/**
 * One keyset page of entry summaries (T-033 AC-69). Pass the three `nextAfter`
 * values back together to get the next page; all three are null on the last
 * page. Rows never carry lines.
 */
final readonly class ListJournalEntriesResult
{
    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function __construct(
        public array $rows,
        public ?string $nextAfterEntryDate,
        public ?string $nextAfterNumber,
        public ?string $nextAfterId,
    ) {}
}
