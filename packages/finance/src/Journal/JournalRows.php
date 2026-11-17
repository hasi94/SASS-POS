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
    /**
     * The columns a summary row carries: every stored column but the payload
     * fingerprint and the generated key column, which are internal.
     *
     * @var list<string>
     */
    public const SUMMARY_COLUMNS = [
        'id', 'number', 'entry_date', 'period_id', 'branch_id', 'source_type', 'source_id', 'source_purpose', 'status',
        'total_debit', 'total_credit', 'description', 'reversal_of_id', 'reversed_by_id', 'posted_at', 'posted_by',
        'is_auto', 'created_by', 'created_at', 'updated_at',
    ];

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

    /**
     * One keyset page of entry summaries (T-033 AC-69), newest first: `entry_date`
     * descending, then `number` descending (string order, D-36), then `id`
     * descending. Never reads a line's amounts, only whether a line names an
     * account (`account_id` filter). `$limit` is the page size plus one, so the
     * handler can tell whether another page exists.
     *
     * The filters arrive already validated, in final form; the cursor is all
     * three parts or none.
     *
     * @param  array<string, string>  $filters  `from`, `to`, `status`, `source_type`, `period_id`, `account_id`, `branch_id`
     * @return list<object>
     */
    public function entriesPageUnlocked(array $filters, int $limit, ?string $afterDate, ?string $afterNumber, ?string $afterId): array
    {
        $query = $this->entries()->select(self::SUMMARY_COLUMNS);

        foreach (['status', 'source_type', 'period_id', 'branch_id'] as $column) {
            if (isset($filters[$column])) {
                $query->where($column, $filters[$column]);
            }
        }

        if (isset($filters['from'])) {
            $query->where('entry_date', '>=', $filters['from']);
        }

        if (isset($filters['to'])) {
            $query->where('entry_date', '<=', $filters['to']);
        }

        if (isset($filters['account_id'])) {
            $accountId = $filters['account_id'];

            $query->whereExists(
                fn (Builder $lines): Builder => $lines->from('journal_lines')
                    ->selectRaw('1')
                    ->whereColumn('journal_lines.entry_id', 'journal_entries.id')
                    ->where('journal_lines.account_id', $accountId),
            );
        }

        if ($afterDate !== null && $afterNumber !== null && $afterId !== null) {
            $query->where(function (Builder $page) use ($afterDate, $afterNumber, $afterId): void {
                $page->where('entry_date', '<', $afterDate)
                    ->orWhere(function (Builder $same) use ($afterDate, $afterNumber, $afterId): void {
                        $same->where('entry_date', $afterDate)->where(function (Builder $tie) use ($afterNumber, $afterId): void {
                            $tie->where('number', '<', $afterNumber)
                                ->orWhere(fn (Builder $last) => $last->where('number', $afterNumber)->where('id', '<', $afterId));
                        });
                    });
            });
        }

        return array_values($query->orderByDesc('entry_date')->orderByDesc('number')->orderByDesc('id')->limit($limit)->get()->all());
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
