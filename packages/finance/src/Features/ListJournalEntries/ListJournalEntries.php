<?php

declare(strict_types=1);

namespace Flexipos\Finance\Features\ListJournalEntries;

/**
 * List journal entries, newest first, keyset-paged by
 * `(entry_date, number, id)` (T-033 AC-69).
 *
 * Every value is `mixed`: a caller's page size, cursor and filter values are
 * validated by the handler, never trusted by a type declaration that would
 * turn `"50"` into 50 before it could be refused.
 */
final class ListJournalEntries
{
    /**
     * @param  array<array-key, mixed>  $filters  `from`, `to`, `status`, `source_type`, `period_id`, `account_id`, `branch_id`
     */
    public function __construct(
        public readonly array $filters = [],
        public readonly mixed $pageSize = null,
        public readonly mixed $afterEntryDate = null,
        public readonly mixed $afterNumber = null,
        public readonly mixed $afterId = null,
        public readonly ?string $actorId = null,
    ) {}
}
