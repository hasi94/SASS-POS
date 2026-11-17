<?php

declare(strict_types=1);

namespace Flexipos\Finance\Features\ListFiscalYears;

use Flexipos\Finance\Exceptions\FinanceRefusal;
use Flexipos\Finance\Exceptions\FinanceRefused;
use Flexipos\Finance\Periods\PeriodRows;
use Flexipos\Finance\Registry\FinancePermissions;
use Flexipos\Finance\Support\Authorize;
use Flexipos\Finance\Support\Input;
use Flexipos\Finance\Support\Page;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * List fiscal years (T-032 plan Ruling 10, AC-62, AC-64).
 *
 * Keyset on `start_date`, which is unique, so the cursor is one value: the
 * page's `nextAfterCode` is the last year's start date and `nextAfterId` is
 * always null. The page size is 1 to the configured maximum and is
 * **refused** outside it, never clamped (the account list's rule, F-13).
 */
final class ListFiscalYearsHandler
{
    public function __construct(
        private readonly Authorize $authorize,
        private readonly PeriodRows $rows,
        private readonly ConfigRepository $config,
    ) {}

    /**
     * @throws FinanceRefused
     */
    public function handle(ListFiscalYears $query): Page
    {
        $this->authorize->require($query->actorId, FinancePermissions::PERIODS_VIEW);

        $size = $this->pageSize($query->pageSize);
        $after = $this->cursor($query->afterStart);

        $rows = $this->rows->yearsPageUnlocked($size + 1, $after);

        $hasMore = count($rows) > $size;
        $rows = array_slice($rows, 0, $size);
        $last = $rows === [] ? null : $rows[count($rows) - 1];

        return new Page(
            array_map(static fn (object $row): array => (array) $row, $rows),
            $hasMore && $last !== null ? (string) $last->start_date : null,
            null,
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

    private function cursor(mixed $after): ?string
    {
        if ($after === null) {
            return null;
        }

        try {
            return Input::date($after, 'after_start');
        } catch (FinanceRefused) {
            throw FinanceRefused::because(
                FinanceRefusal::CursorInvalid,
                'The cursor must be the start date returned by the previous page.',
                'cursor',
            );
        }
    }
}
