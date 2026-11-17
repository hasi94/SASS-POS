<?php

declare(strict_types=1);

namespace Flexipos\Finance\Journal;

use Flexipos\Contracts\Access\EmployeeDirectory;
use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Contracts\Finance\AccountRefused;
use Flexipos\Contracts\Finance\ChartOfAccounts;
use Flexipos\Contracts\Finance\JournalDimension;
use Flexipos\Contracts\Finance\JournalEntryStatus;
use Flexipos\Contracts\Finance\JournalSourceType;
use Flexipos\Contracts\Finance\PeriodClearance;
use Flexipos\Contracts\Finance\PeriodGate;
use Flexipos\Contracts\Finance\PeriodGateRefused;
use Flexipos\Contracts\Finance\PostedEntry;
use Flexipos\Contracts\Finance\PostingRefusal;
use Flexipos\Contracts\Finance\PostingRefused;
use Flexipos\Contracts\Finance\PostingRequest;
use Flexipos\Contracts\Finance\PostingService;
use Flexipos\Contracts\Finance\ReversalRequest;
use Flexipos\Contracts\Organization\DocumentNumbers;
use Flexipos\Finance\Exceptions\FinanceRefused;
use Flexipos\Finance\Registry\FinanceAuditActions;
use Flexipos\Finance\Support\Input;
use Flexipos\Finance\Support\TenantConnection;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Database\Connection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;

/**
 * The published posting service, answered from the tenant database (T-033
 * Rulings 3 and 6 to 9, AC-2 to AC-4, AC-22 to AC-58).
 *
 * **Runs inside the caller's transaction and never opens, retries or commits
 * one** (AC-2): called outside a transaction it throws before it parses or
 * reads anything. It checks no permission (D-10); the human handlers do.
 *
 * `post`, in order (D-21 as amended by D-26 and D-38): the pure checks of
 * {@see PostingInput} (shape, amounts, balance), the actor, the idempotent
 * replay, the period gate, the accounts (ascending id), the references, then a
 * **savepoint** that allocates the number, writes the entry and all its lines
 * in one statement each, and writes one audit row. A refusal, a replay or a
 * rolled-back caller therefore consumes no number (AC-33).
 *
 * `reverse`, in order: shape, actor, the original (plain read), the replay on
 * the reversal key, the date rule, the gate, the original's accounts, the
 * original `FOR UPDATE`, then the same savepoint. It does **not** re-check the
 * liveness of the branch or any dimension (AC-55, D-35).
 *
 * **Lock order (Ruling 9)**: period shared, accounts shared ascending, [the
 * original `FOR UPDATE`], the `journal_entry` sequence row `FOR UPDATE`, then
 * the inserts. Callers must call this **after every other lock they take,
 * including their own document number**, and several calls in one transaction
 * must be in ascending `entry_date` order (the gate's rule). Every `[ENGINE]`
 * claim about these locks is NOT VERIFIED under ENGINE_HOLD.
 *
 * The savepoint is where a lost race ends: a unique violation on the
 * idempotency key rolls the savepoint back (undoing the number), and the key
 * is re-read `FOR UPDATE` to answer with a replay or a conflict (AC-40). Any
 * other failure propagates; there is no retry and no deadlock catch, the
 * caller's own `transaction(fn, attempts: 2)` owns that.
 *
 * A fresh {@see PostedEntry} is built from the computed strings and never
 * re-read: the stand-in returns DECIMAL as a float.
 */
final class DatabasePostingService implements PostingService
{
    public const ACTION_POSTED = 'journal_entry_posted';

    public const ACTION_REVERSED = 'journal_entry_reversed';

    /**
     * The sequence every entry takes its number from (company-wide, D-8).
     */
    private const DOCUMENT_TYPE = 'journal_entry';

    /**
     * The stored `source_purpose_key` of an entry with no purpose.
     */
    private const NO_PURPOSE = '-';

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly EmployeeDirectory $employees,
        private readonly PeriodGate $gate,
        private readonly ChartOfAccounts $accounts,
        private readonly DocumentNumbers $numbers,
        private readonly AuditTrail $audit,
        private readonly JournalRows $rows,
        private readonly JournalWriter $writer,
        private readonly JournalReferences $references,
        private readonly RunnerClock $clock,
    ) {}

    public function post(PostingRequest $request): PostedEntry
    {
        $connection = $this->requireTransaction($this->tenant->get(), 'post');

        $posting = PostingInput::parse($request);
        $actor = $this->liveActor($posting->actorId);

        $purposeKey = $posting->sourcePurpose ?? self::NO_PURPOSE;
        $fingerprint = Fingerprint::ofPosting($posting);

        if ($posting->sourceId !== null) {
            $stored = $this->rows->byKeyUnlocked($posting->sourceType->value, $posting->sourceId, $purposeKey);

            if ($stored !== null) {
                return $this->replayOrConflict($stored, $fingerprint, PostingRefusal::IdempotencyConflict);
            }
        }

        $clearance = $this->clear($posting->entryDate, $actor);

        $this->checkAccounts(array_column($posting->lines, 'account_id'));
        $this->references->check($posting);

        try {
            return $connection->transaction(fn (): PostedEntry => $this->write($posting, $actor, $clearance, $fingerprint));
        } catch (UniqueConstraintViolationException $violation) {
            if ($posting->sourceId === null) {
                throw $violation;
            }

            $winner = $this->rows->byKeyForUpdate($posting->sourceType->value, $posting->sourceId, $purposeKey);

            if ($winner === null) {
                throw $violation;
            }

            return $this->replayOrConflict($winner, $fingerprint, PostingRefusal::IdempotencyConflict);
        }
    }

    public function reverse(ReversalRequest $request): PostedEntry
    {
        $connection = $this->requireTransaction($this->tenant->get(), 'reverse');

        $reversal = ReversalInput::parse($request);
        $actor = $this->liveActor($reversal->actorId);

        $original = $this->rows->entryByIdUnlocked($reversal->entryId);

        if ($original === null) {
            throw PostingRefused::because(PostingRefusal::EntryNotFound, 'There is no journal entry ['.$reversal->entryId.'].', 'entry_id');
        }

        if ((string) $original->source_type === JournalSourceType::Reversal->value
            || (string) $original->status === JournalEntryStatus::Draft->value) {
            throw PostingRefused::because(PostingRefusal::NotReversible, 'The entry cannot be reversed.', 'entry_id');
        }

        $fingerprint = Fingerprint::ofReversal($reversal->entryDate, $reversal->reason);

        $stored = $this->rows->byKeyUnlocked(JournalSourceType::Reversal->value, $reversal->entryId, self::NO_PURPOSE);

        if ($stored !== null) {
            return $this->replayOrConflict($stored, $fingerprint, PostingRefusal::AlreadyReversed);
        }

        if ($reversal->entryDate < (string) $original->entry_date) {
            throw PostingRefused::because(
                PostingRefusal::ReversalBeforeOriginal,
                'A reversal cannot be dated before the entry it reverses.',
                'entry_date',
            );
        }

        $clearance = $this->clear($reversal->entryDate, $actor);

        $originalLines = $this->rows->linesOfEntryUnlocked($reversal->entryId);

        $this->checkAccounts(array_map(static fn (object $line): string => (string) $line->account_id, $originalLines));

        $locked = $this->rows->entryForUpdate($reversal->entryId);

        if ($locked === null || (string) $locked->status !== JournalEntryStatus::Posted->value) {
            return $this->lostReversal($reversal->entryId, $fingerprint);
        }

        try {
            return $connection->transaction(
                fn (): PostedEntry => $this->writeReversal($locked, $originalLines, $reversal, $actor, $clearance, $fingerprint),
            );
        } catch (UniqueConstraintViolationException $violation) {
            $winner = $this->rows->byKeyForUpdate(JournalSourceType::Reversal->value, $reversal->entryId, self::NO_PURPOSE);

            if ($winner === null) {
                throw $violation;
            }

            return $this->replayOrConflict($winner, $fingerprint, PostingRefusal::AlreadyReversed);
        }
    }

    // -- Guards -------------------------------------------------------------

    private function requireTransaction(Connection $connection, string $operation): Connection
    {
        if ($connection->transactionLevel() < 1) {
            throw new LogicException("PostingService::{$operation}() must be called inside the caller's transaction (AC-2).");
        }

        return $connection;
    }

    /**
     * The folded actor id, or null for a system posting (AC-73a, AC-74). The
     * first read of the call, and only for a non-null actor.
     *
     * @throws PostingRefused invalid_actor
     */
    private function liveActor(?string $actorId): ?string
    {
        if ($actorId === null) {
            return null;
        }

        try {
            $folded = Input::id($actorId, 'actor_id');
        } catch (FinanceRefused) {
            throw PostingRefused::because(PostingRefusal::InvalidActor, 'The actor is not a valid employee id.', 'actor_id');
        }

        if (! $this->employees->isLive($folded)) {
            throw PostingRefused::because(PostingRefusal::InvalidActor, 'The actor is not a live employee.', 'actor_id');
        }

        return $folded;
    }

    private function clear(string $date, ?string $actor): PeriodClearance
    {
        try {
            return $this->gate->clear($date, $actor);
        } catch (PeriodGateRefused $refused) {
            throw PostingRefused::fromPeriodGate($refused, 'entry_date');
        }
    }

    /**
     * Each distinct account once, in ascending id order (upper-case ULIDs, so
     * `strcmp` order is the order `AccountRows::allForUpdate` locks in, AC-25),
     * through `ChartOfAccounts::postable`. The first refusal passes through
     * unchanged and names the first line that carries the account (AC-23).
     *
     * @param  list<string>  $accountIds  in line order
     */
    private function checkAccounts(array $accountIds): void
    {
        $firstLine = [];

        foreach ($accountIds as $index => $id) {
            $firstLine[$id] ??= $index;
        }

        ksort($firstLine, SORT_STRING);

        foreach ($firstLine as $id => $index) {
            try {
                $this->accounts->postable((string) $id);
            } catch (AccountRefused $refused) {
                throw PostingRefused::fromAccount($refused, 'lines.'.$index.'.account_id', ['line_index' => $index]);
            }
        }
    }

    // -- Replay -------------------------------------------------------------

    /**
     * The stored entry as a replay when the fingerprint matches (AC-34), or the
     * refusal `$conflict` carrying the stored id when it does not (AC-35).
     */
    private function replayOrConflict(object $stored, string $fingerprint, PostingRefusal $conflict): PostedEntry
    {
        if (! hash_equals((string) $stored->payload_fingerprint, $fingerprint)) {
            throw PostingRefused::because(
                $conflict,
                'The key is already used by a different payload.',
                null,
                ['entry_id' => (string) $stored->id],
            );
        }

        return new PostedEntry(
            (string) $stored->id,
            (string) $stored->number,
            (string) $stored->period_id,
            (string) $stored->entry_date,
            Amounts::fromStored($stored->total_debit),
            Amounts::fromStored($stored->total_credit),
            JournalEntryStatus::from((string) $stored->status),
            true,
        );
    }

    /**
     * The original is no longer `posted` once locked: somebody reversed it
     * between the plain read and the lock (AC-57). Answer from the reversal.
     */
    private function lostReversal(string $originalId, string $fingerprint): PostedEntry
    {
        $winner = $this->rows->byKeyForUpdate(JournalSourceType::Reversal->value, $originalId, self::NO_PURPOSE);

        if ($winner === null) {
            throw PostingRefused::because(PostingRefusal::AlreadyReversed, 'The entry is already reversed.', 'entry_id', ['entry_id' => $originalId]);
        }

        return $this->replayOrConflict($winner, $fingerprint, PostingRefusal::AlreadyReversed);
    }

    // -- The savepoint bodies -----------------------------------------------

    private function write(ParsedPosting $posting, ?string $actor, PeriodClearance $clearance, string $fingerprint): PostedEntry
    {
        $number = $this->numbers->next(self::DOCUMENT_TYPE, null);
        $id = $this->newId();
        $now = $this->clock->timestamp();

        $this->writer->insertEntry([
            'id' => $id,
            'number' => $number,
            'entry_date' => $posting->entryDate,
            'period_id' => $clearance->periodId,
            'branch_id' => $posting->branchId,
            'source_type' => $posting->sourceType->value,
            'source_id' => $posting->sourceId,
            'source_purpose' => $posting->sourcePurpose,
            'status' => JournalEntryStatus::Posted->value,
            'total_debit' => $posting->totalDebit,
            'total_credit' => $posting->totalCredit,
            'description' => $posting->description,
            'reversal_of_id' => null,
            'reversed_by_id' => null,
            'payload_fingerprint' => $fingerprint,
            'posted_at' => $now,
            'posted_by' => $actor,
            'is_auto' => $actor === null,
            'created_by' => $actor,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->writer->insertLines($this->lineRows($id, $posting->lines, $now));

        $this->audit->record(
            FinanceAuditActions::MODULE,
            $actor,
            self::ACTION_POSTED,
            'journal_entry',
            $id,
            null,
            [
                'number' => $number,
                'source_type' => $posting->sourceType->value,
                'source_id' => $posting->sourceId,
                'source_purpose' => $posting->sourcePurpose,
                'entry_date' => $posting->entryDate,
                'period_id' => $clearance->periodId,
                'total_debit' => $posting->totalDebit,
                'total_credit' => $posting->totalCredit,
                'line_count' => count($posting->lines),
                'is_auto' => $actor === null,
            ],
        );

        return new PostedEntry(
            $id,
            $number,
            $clearance->periodId,
            $posting->entryDate,
            $posting->totalDebit,
            $posting->totalCredit,
            JournalEntryStatus::Posted,
            false,
        );
    }

    /**
     * @param  list<object>  $originalLines
     */
    private function writeReversal(
        object $original,
        array $originalLines,
        ParsedReversal $reversal,
        ?string $actor,
        PeriodClearance $clearance,
        string $fingerprint,
    ): PostedEntry {
        $number = $this->numbers->next(self::DOCUMENT_TYPE, null);
        $id = $this->newId();
        $now = $this->clock->timestamp();
        $originalId = (string) $original->id;
        $totalDebit = Amounts::fromStored($original->total_debit);
        $totalCredit = Amounts::fromStored($original->total_credit);

        $this->writer->insertEntry([
            'id' => $id,
            'number' => $number,
            'entry_date' => $reversal->entryDate,
            'period_id' => $clearance->periodId,
            'branch_id' => $original->branch_id === null ? null : (string) $original->branch_id,
            'source_type' => JournalSourceType::Reversal->value,
            'source_id' => $originalId,
            'source_purpose' => null,
            'status' => JournalEntryStatus::Posted->value,
            'total_debit' => $totalCredit,
            'total_credit' => $totalDebit,
            'description' => $reversal->reason,
            'reversal_of_id' => $originalId,
            'reversed_by_id' => null,
            'payload_fingerprint' => $fingerprint,
            'posted_at' => $now,
            'posted_by' => $actor,
            'is_auto' => $actor === null,
            'created_by' => $actor,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $lines = [];

        foreach ($originalLines as $line) {
            $dimensions = [];

            foreach (JournalDimension::cases() as $dimension) {
                $value = $line->{$dimension->value};
                $dimensions[$dimension->value] = $value === null ? null : (string) $value;
            }

            $lines[] = [
                'account_id' => (string) $line->account_id,
                'debit' => Amounts::fromStored($line->credit),
                'credit' => Amounts::fromStored($line->debit),
                'memo' => $line->memo === null ? null : (string) $line->memo,
                'dimensions' => $dimensions,
            ];
        }

        $this->writer->insertLines($this->lineRows($id, $lines, $now));

        if ($this->writer->markReversed($originalId, $id, $now) !== 1) {
            throw new RuntimeException("The entry [{$originalId}] was locked as posted but could not be marked reversed.");
        }

        $this->audit->record(
            FinanceAuditActions::MODULE,
            $actor,
            self::ACTION_REVERSED,
            'journal_entry',
            $id,
            null,
            [
                'number' => $number,
                'source_type' => JournalSourceType::Reversal->value,
                'source_id' => $originalId,
                'source_purpose' => null,
                'entry_date' => $reversal->entryDate,
                'period_id' => $clearance->periodId,
                'total_debit' => $totalCredit,
                'total_credit' => $totalDebit,
                'line_count' => count($lines),
                'is_auto' => $actor === null,
                'reason' => $reversal->reason,
                'original_id' => $originalId,
            ],
        );

        return new PostedEntry(
            $id,
            $number,
            $clearance->periodId,
            $reversal->entryDate,
            $totalCredit,
            $totalDebit,
            JournalEntryStatus::Posted,
            false,
        );
    }

    /**
     * The rows of one entry's lines, `line_no` 1 to N in line order.
     *
     * @param  list<array{account_id: string, debit: string, credit: string, memo: ?string, dimensions: array<string, ?string>}>  $lines
     * @return list<array<string, mixed>>
     */
    private function lineRows(string $entryId, array $lines, string $now): array
    {
        $rows = [];

        foreach ($lines as $index => $line) {
            $row = [
                'id' => $this->newId(),
                'entry_id' => $entryId,
                'line_no' => $index + 1,
                'account_id' => $line['account_id'],
                'debit' => $line['debit'],
                'credit' => $line['credit'],
                'memo' => $line['memo'],
            ];

            foreach (JournalDimension::cases() as $dimension) {
                $row[$dimension->value] = $line['dimensions'][$dimension->value] ?? null;
            }

            $row['created_at'] = $now;
            $rows[] = $row;
        }

        return $rows;
    }

    private function newId(): string
    {
        return strtoupper((string) Str::ulid());
    }
}
