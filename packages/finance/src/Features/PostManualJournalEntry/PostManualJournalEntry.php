<?php

declare(strict_types=1);

namespace Flexipos\Finance\Features\PostManualJournalEntry;

/**
 * Post one manual journal entry by hand (T-033 AC-71).
 *
 * `body` is the decoded request: `source_id`, `source_purpose`, `entry_date`,
 * `description`, `branch_id` and `lines`. It never names `source_type`: the
 * handler fixes it to `manual`, and a body that does is refused as an unknown
 * field.
 */
final class PostManualJournalEntry
{
    /**
     * @param  array<array-key, mixed>  $body
     */
    public function __construct(
        public readonly array $body,
        public readonly ?string $actorId = null,
    ) {}
}
