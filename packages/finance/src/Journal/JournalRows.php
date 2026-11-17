<?php

declare(strict_types=1);

namespace Flexipos\Finance\Journal;

use Flexipos\Finance\Support\TenantConnection;
use Illuminate\Database\Query\Builder;

/**
 * Every read of `journal_entries` and `journal_lines` (T-033 Ruling 1, the
 * `AccountRows` and `PeriodRows` construction).
 *
 * **A method's name states its lock mode.** `*ForUpdate` locks the row the
 * caller is about to decide on; `*Unlocked` is a plain read. The two plain
 * reads of the posting path are deliberate (Ruling 7 step 2): a shared-lock
 * replay look-up would take a gap lock on a missing key, and two first posts of
 * one key would then deadlock on the insert that follows. The lines of an entry
 * are never updated, so reading them plain is safe.
 *
 * Reads only. The one writer of journal rows is {@see JournalWriter}.
 *
 * Not final, on purpose: a test replaces `entryForUpdate` to flip an entry
 * between the plain read and the lock, because the stand-in has no second
 * connection to race with (AC-57).
 */
class JournalRows
{
    public function __construct(private readonly TenantConnection $tenant) {}

    // -- Plain reads --------------------------------------------------------

    /**
     * The entry stored under an idempotency key, or null. `$purposeKey` is the
     * stored key column: the purpose, or `'-'` for none.
     */
    public function byKeyUnlocked(string $sourceType, string $sourceId, string $purposeKey): ?object
    {
        return $this->entries()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('source_purpose_key', $purposeKey)
            ->first();
    }

    public function entryByIdUnlocked(string $id): ?object
    {
        return $this->entries()->where('id', $id)->first();
    }

    /**
     * An entry's lines by `line_no`. Lines are immutable once written, so the
     * read takes no lock.
     *
     * @return list<object>
     */
    public function linesOfEntryUnlocked(string $entryId): array
    {
        return array_values($this->lines()->where('entry_id', $entryId)->orderBy('line_no')->get()->all());
    }

    // -- Locking reads ------------------------------------------------------

    /**
     * The entry stored under a key, `FOR UPDATE`: the re-read after a unique
     * violation, which waits for the winner to commit (AC-40).
     */
    public function byKeyForUpdate(string $sourceType, string $sourceId, string $purposeKey): ?object
    {
        return $this->entries()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('source_purpose_key', $purposeKey)
            ->lockForUpdate()
            ->first();
    }

    /**
     * The entry about to be reversed, `FOR UPDATE` (AC-57).
     */
    public function entryForUpdate(string $id): ?object
    {
        return $this->entries()->where('id', $id)->lockForUpdate()->first();
    }

    private function entries(): Builder
    {
        return $this->tenant->get()->table('journal_entries');
    }

    private function lines(): Builder
    {
        return $this->tenant->get()->table('journal_lines');
    }
}
