<?php

declare(strict_types=1);

namespace Flexipos\Finance\Features\ListJournalEntries;

use Flexipos\Contracts\Finance\JournalEntryStatus;
use Flexipos\Contracts\Finance\JournalSourceType;
use Flexipos\Finance\Exceptions\FinanceRefusal;
use Flexipos\Finance\Exceptions\FinanceRefused;
use Flexipos\Finance\Journal\EntryView;
use Flexipos\Finance\Journal\JournalRows;
use Flexipos\Finance\Registry\FinancePermissions;
use Flexipos\Finance\Support\Authorize;
use Flexipos\Finance\Support\Fields;
use Flexipos\Finance\Support\Input;
use Flexipos\Finance\Support\Limits;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * List journal entries (T-033 Ruling 12, AC-69, D-36).
 *
 * Authorized first, then every input is validated before a statement runs: the
 * page size (1 to the configured maximum, **refused** and never clamped), the
 * cursor (all three parts or none), and each filter with the same strict
 * parsers a write uses, so `Posted` is refused exactly as it is anywhere else.
 * `from` after `to` is a valid request with an empty answer.
 *
 * **The order is `entry_date` descending, then `number` descending as a
 * string, then `id`** (D-36). String order equals numeric order only while
 * every number has the same width, that is below 1,000,000 entries at the
 * seeded padding and with an unchanged prefix; changing either must revisit
 * this order. Rows are summaries and never carry lines.
 */
final class ListJournalEntriesHandler
{
    private const FILTERS = ['from', 'to', 'status', 'source_type', 'period_id', 'account_id', 'branch_id'];

    public function __construct(
        private readonly Authorize $authorize,
        private readonly JournalRows $rows,
        private readonly ConfigRepository $config,
    ) {}

    /**
     * @throws FinanceRefused
     */
    public function handle(ListJournalEntries $query): ListJournalEntriesResult
    {
        $this->authorize->require($query->actorId, FinancePermissions::JOURNAL_VIEW);

        $size = $this->pageSize($query->pageSize);
        [$afterDate, $afterNumber, $afterId] = $this->cursor($query->afterEntryDate, $query->afterNumber, $query->afterId);
        $filters = $this->filters($query->filters);

        $rows = $this->rows->entriesPageUnlocked($filters, $size + 1, $afterDate, $afterNumber, $afterId);

        $hasMore = count($rows) > $size;
        $rows = array_slice($rows, 0, $size);
        $last = $rows === [] ? null : $rows[count($rows) - 1];

        return new ListJournalEntriesResult(
            array_map(EntryView::summary(...), $rows),
            $hasMore && $last !== null ? (string) $last->entry_date : null,
            $hasMore && $last !== null ? (string) $last->number : null,
            $hasMore && $last !== null ? (string) $last->id : null,
        );
    }

    private function pageSize(mixed $requested): int
    {
        $default = (int) $this->config->get('finance.page_size', 50);
        $max = (int) $this->config->get('finance.max_page_size', 200);

        if ($requested === null) {
            return $default;
        }

        if (! is_int($requested) || $requested < 1 || $requested > $max) {
            throw FinanceRefused::because(
                FinanceRefusal::PageSizeInvalid,
                "The page size must be an integer from 1 to {$max}.",
                'page_size',
            );
        }

        return $requested;
    }

    /**
     * @return array{0: ?string, 1: ?string, 2: ?string}
     */
    private function cursor(mixed $date, mixed $number, mixed $id): array
    {
        if ($date === null && $number === null && $id === null) {
            return [null, null, null];
        }

        try {
            if ($date === null || $number === null || $id === null) {
                throw FinanceRefused::validation('cursor', 'All three parts of the cursor are required.');
            }

            if (! is_string($number) || preg_match('/\A[\x21-\x7E]{1,'.Limits::NUMBER.'}\z/', $number) !== 1) {
                throw FinanceRefused::validation('after_number', 'The after_number is not an entry number.');
            }

            return [Input::date($date, 'after_entry_date'), $number, Input::id($id, 'after_id')];
        } catch (FinanceRefused) {
            throw FinanceRefused::because(
                FinanceRefusal::CursorInvalid,
                'The cursor must carry after_entry_date, after_number and after_id, as returned by the previous page.',
                'cursor',
            );
        }
    }

    /**
     * @param  array<array-key, mixed>  $filters
     * @return array<string, string> validated, in final form
     */
    private function filters(array $filters): array
    {
        Fields::only($filters, self::FILTERS);

        $valid = [];

        foreach (['from', 'to'] as $bound) {
            if (array_key_exists($bound, $filters)) {
                try {
                    $valid[$bound] = Input::date($filters[$bound], $bound);
                } catch (FinanceRefused) {
                    throw FinanceRefused::validation($bound, "The {$bound} must be a real date in Y-m-d form.");
                }
            }
        }

        if (array_key_exists('status', $filters)) {
            $valid['status'] = Input::enum($filters['status'], JournalEntryStatus::class, 'status')->value;
        }

        if (array_key_exists('source_type', $filters)) {
            $valid['source_type'] = Input::enum($filters['source_type'], JournalSourceType::class, 'source_type')->value;
        }

        foreach (['period_id', 'account_id', 'branch_id'] as $reference) {
            if (array_key_exists($reference, $filters)) {
                $valid[$reference] = Input::id($filters[$reference], $reference);
            }
        }

        return $valid;
    }
}
