<?php

declare(strict_types=1);

namespace Flexipos\Finance\Features\GetJournalEntry;

use Flexipos\Contracts\Finance\JournalSourceType;
use Flexipos\Finance\Exceptions\FinanceRefusal;
use Flexipos\Finance\Exceptions\FinanceRefused;
use Flexipos\Finance\Journal\EntryView;
use Flexipos\Finance\Journal\JournalRows;
use Flexipos\Finance\Journal\PostingInput;
use Flexipos\Finance\Registry\FinancePermissions;
use Flexipos\Finance\Support\Authorize;
use Flexipos\Finance\Support\Input;

/**
 * Read one journal entry with its lines (T-033 Ruling 12, AC-68, D-37).
 *
 * Needs `finance.journal.view`, checked before anything is read. An entry is
 * addressed by its id, or by `(source_type, source_id, source_purpose)`: an
 * absent purpose reads the stored `-` key, and a `-` supplied by a caller is a
 * malformed purpose, so it cannot alias the null-purpose row. An unknown id or
 * key is `entry_not_found`, the same string `PostingRefusal` carries. Throws
 * only {@see FinanceRefused}.
 */
final class GetJournalEntryHandler
{
    /** The stored `source_purpose_key` of an entry with no purpose. */
    private const NO_PURPOSE = '-';

    public function __construct(
        private readonly Authorize $authorize,
        private readonly JournalRows $rows,
    ) {}

    /**
     * @throws FinanceRefused
     */
    public function handle(GetJournalEntry $query): GetJournalEntryResult
    {
        $this->authorize->require($query->actorId, FinancePermissions::JOURNAL_VIEW);

        $entry = $query->entryId !== null ? $this->byId($query) : $this->bySource($query);

        $lines = $this->rows->linesOfEntryUnlocked((string) $entry->id);

        return new GetJournalEntryResult(
            EntryView::summary($entry),
            array_map(EntryView::line(...), $lines),
        );
    }

    private function byId(GetJournalEntry $query): object
    {
        if ($query->sourceType !== null || $query->sourceId !== null || $query->sourcePurpose !== null) {
            throw FinanceRefused::validation('entry_id', 'Name the entry by its id or by its source key, not both.');
        }

        $id = Input::id($query->entryId, 'entry_id');
        $entry = $this->rows->entryByIdUnlocked($id);

        if ($entry === null) {
            throw FinanceRefused::because(FinanceRefusal::EntryNotFound, "There is no journal entry [{$id}].", 'entry_id');
        }

        return $entry;
    }

    private function bySource(GetJournalEntry $query): object
    {
        $type = Input::enum($query->sourceType, JournalSourceType::class, 'source_type');
        $sourceId = Input::id($query->sourceId, 'source_id');
        $purpose = $this->purpose($query->sourcePurpose);

        $entry = $this->rows->byKeyUnlocked($type->value, $sourceId, $purpose ?? self::NO_PURPOSE);

        if ($entry === null) {
            throw FinanceRefused::because(FinanceRefusal::EntryNotFound, 'There is no journal entry with that source key.', 'source_id');
        }

        return $entry;
    }

    private function purpose(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value) || preg_match(PostingInput::PURPOSE, $value) !== 1) {
            throw FinanceRefused::validation('source_purpose', 'The source_purpose must be 1 to 30 lower-case letters, digits or underscores, starting with a letter.');
        }

        return $value;
    }
}
