<?php

declare(strict_types=1);

namespace Flexipos\Finance\Features\ListAccounts;

use Flexipos\Contracts\Finance\AccountSubtype;
use Flexipos\Contracts\Finance\AccountType;
use Flexipos\Contracts\Finance\ControlType;
use Flexipos\Finance\Accounts\AccountRows;
use Flexipos\Finance\Exceptions\FinanceRefusal;
use Flexipos\Finance\Exceptions\FinanceRefused;
use Flexipos\Finance\Registry\FinancePermissions;
use Flexipos\Finance\Support\Authorize;
use Flexipos\Finance\Support\Fields;
use Flexipos\Finance\Support\Input;
use Flexipos\Finance\Support\Limits;
use Flexipos\Finance\Support\Page;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * List accounts (T-032 plan Ruling 10, AC-61, AC-64).
 *
 * Authorized first, then every input is validated before a statement runs:
 * the page size (1 to the configured maximum, **refused** and never clamped,
 * F-13), the cursor (both halves or neither), and each filter value with the
 * same strict parsers a write uses, so `Asset` is refused here exactly as it
 * is on a create (AC-5). Soft-deleted rows never appear.
 */
final class ListAccountsHandler
{
    private const FILTERS = ['type', 'subtype', 'parent_id', 'is_active', 'is_postable', 'control_type', 'q'];

    public function __construct(
        private readonly Authorize $authorize,
        private readonly AccountRows $rows,
        private readonly ConfigRepository $config,
    ) {}

    /**
     * @throws FinanceRefused
     */
    public function handle(ListAccounts $query): Page
    {
        $this->authorize->require($query->actorId, FinancePermissions::ACCOUNTS_VIEW);

        $size = $this->pageSize($query->pageSize);
        [$afterCode, $afterId] = $this->cursor($query->afterCode, $query->afterId);
        $filters = $this->filters($query->filters);

        $rows = $this->rows->pageUnlocked($filters, $size + 1, $afterCode, $afterId);

        $hasMore = count($rows) > $size;
        $rows = array_slice($rows, 0, $size);
        $last = $rows === [] ? null : $rows[count($rows) - 1];

        return new Page(
            array_map(static fn (object $row): array => (array) $row, $rows),
            $hasMore && $last !== null ? (string) $last->code : null,
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
     * @return array{0: ?string, 1: ?string}
     */
    private function cursor(mixed $code, mixed $id): array
    {
        if ($code === null && $id === null) {
            return [null, null];
        }

        try {
            if ($code === null || $id === null) {
                throw FinanceRefused::validation('cursor', 'Both halves of the cursor are required.');
            }

            return [Input::code($code, 'after_code'), Input::id($id, 'after_id')];
        } catch (FinanceRefused) {
            throw FinanceRefused::because(
                FinanceRefusal::CursorInvalid,
                'The cursor must carry both after_code and after_id, as returned by the previous page.',
                'cursor',
            );
        }
    }

    /**
     * @param  array<array-key, mixed>  $filters
     * @return array<string, mixed> validated, in final form
     */
    private function filters(array $filters): array
    {
        Fields::only($filters, self::FILTERS);

        $valid = [];

        if (array_key_exists('type', $filters)) {
            $valid['type'] = Input::enum($filters['type'], AccountType::class, 'type')->value;
        }

        if (array_key_exists('subtype', $filters)) {
            $valid['subtype'] = Input::enum($filters['subtype'], AccountSubtype::class, 'subtype')->value;
        }

        if (array_key_exists('control_type', $filters)) {
            $valid['control_type'] = Input::enum($filters['control_type'], ControlType::class, 'control_type')->value;
        }

        if (array_key_exists('parent_id', $filters)) {
            $valid['parent_id'] = $filters['parent_id'] === null ? null : Input::id($filters['parent_id'], 'parent_id');
        }

        foreach (['is_active', 'is_postable'] as $flag) {
            if (array_key_exists($flag, $filters)) {
                $valid[$flag] = Input::flag($filters[$flag], $flag);
            }
        }

        if (array_key_exists('q', $filters)) {
            $valid['q'] = Input::text($filters['q'], Limits::SEARCH, true, 'q');
        }

        return $valid;
    }
}
