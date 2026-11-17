<?php

declare(strict_types=1);

namespace Flexipos\Finance\Features\GetJournalEntry;

/**
 * One entry and its lines (T-033 AC-68).
 */
final readonly class GetJournalEntryResult
{
    /**
     * @param  array<string, mixed>  $entry  the summary fields, amounts as four-decimal strings
     * @param  list<array<string, mixed>>  $lines  ordered by `line_no`
     */
    public function __construct(
        public array $entry,
        public array $lines,
    ) {}
}
