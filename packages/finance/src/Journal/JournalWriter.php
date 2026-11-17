<?php

declare(strict_types=1);

namespace Flexipos\Finance\Journal;

use Flexipos\Finance\Support\TenantConnection;

/**
 * The only writer of `journal_entries` and `journal_lines` (T-033 AC-5, D-17).
 *
 * Three operations and no others: insert an entry, insert all of its lines in
 * **one statement** (AC-46), and flip an original to `reversed`. There is no
 * delete and no other update (AC-47, AC-48): the flip sets exactly `status`,
 * `reversed_by_id` and `updated_at`, and only where `status = 'posted'`, so it
 * is a compare-and-set.
 *
 * Not final, on purpose: a test replaces `insertLines` to fail the line insert
 * after the entry insert (AC-46) and `markReversed` to lose the compare-and-set.
 */
class JournalWriter
{
    public function __construct(private readonly TenantConnection $tenant) {}

    /**
     * @param  array<string, mixed>  $row
     */
    public function insertEntry(array $row): void
    {
        $this->tenant->get()->table('journal_entries')->insert($row);
    }

    /**
     * Every line of one entry in a single `insert` statement.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public function insertLines(array $rows): void
    {
        $this->tenant->get()->table('journal_lines')->insert($rows);
    }

    /**
     * Flip a posted entry to reversed; returns the number of rows changed
     * (1, or 0 when the entry was no longer `posted`).
     */
    public function markReversed(string $id, string $reversalId, string $now): int
    {
        return $this->tenant->get()->table('journal_entries')
            ->where('id', $id)
            ->where('status', 'posted')
            ->update([
                'status' => 'reversed',
                'reversed_by_id' => $reversalId,
                'updated_at' => $now,
            ]);
    }
}
