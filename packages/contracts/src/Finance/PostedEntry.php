<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Finance;

/**
 * What {@see PostingService} returns, and nothing else (T-033 AC-4).
 *
 * `totalDebit` and `totalCredit` are four-decimal strings. `replayed` is true
 * only when the call found the entry already posted for its key (AC-34).
 */
final readonly class PostedEntry
{
    public function __construct(
        public string $id,
        public string $number,
        public string $periodId,
        public string $entryDate,
        public string $totalDebit,
        public string $totalCredit,
        public JournalEntryStatus $status,
        public bool $replayed,
    ) {}
}
