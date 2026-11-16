<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Support;

use Flexipos\Kernel\Audit\PageSize;
use Flexipos\Purchasing\Exceptions\PurchasingRefused;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Query\Builder;

/**
 * The keyset pagers every list in this package uses (T-034 D-34, the sales
 * `Pager` shape): documents newest first with `before_id`, suppliers ascending
 * by code with `after_code`.
 *
 * `purchasing.page_size` and `purchasing.max_page_size` are the one authority
 * for the numbers. **Validate first, clamp second** (AC-16, D-34): a
 * `page_size` that is not an integer or is below 1 is `validation`, a larger one
 * than the maximum is clamped, never refused. This is a deliberate difference
 * from the kernel `PageSize`, where `< 1` means the default (T-014 D-28). A
 * malformed cursor is `validation`.
 */
final class Pager
{
    /**
     * The page size to use: the default when absent, the caller's when a valid
     * integer, never more than the maximum.
     *
     * @throws PurchasingRefused validation
     */
    public static function size(ConfigRepository $config, mixed $pageSize): int
    {
        if ($pageSize !== null && (! is_int($pageSize) || $pageSize < 1)) {
            throw PurchasingRefused::validation('The page_size must be an integer of at least 1.');
        }

        return PageSize::clamp(
            $pageSize,
            (int) $config->get('purchasing.page_size', 25),
            (int) $config->get('purchasing.max_page_size', 100),
        );
    }

    /**
     * Newest first by the ULID `id`.
     *
     * @throws PurchasingRefused validation
     */
    public static function page(Builder $builder, ConfigRepository $config, mixed $pageSize, mixed $beforeId): Page
    {
        $size = self::size($config, $pageSize);

        if ($beforeId !== null) {
            $builder->where('id', '<', Identifier::fold($beforeId, 'before_id'));
        }

        $rows = $builder->orderByDesc('id')->limit($size + 1)->get()->all();
        $hasMore = count($rows) > $size;
        $rows = array_slice($rows, 0, $size);

        return new Page(
            self::arrays($rows),
            $hasMore ? (string) ((array) $rows[count($rows) - 1])['id'] : null,
        );
    }

    /**
     * Ascending by `code`, for suppliers. The cursor is the last code of the
     * previous page; one past the end, or no match, is an empty page.
     *
     * @throws PurchasingRefused validation
     */
    public static function pageByCode(Builder $builder, ConfigRepository $config, mixed $pageSize, mixed $afterCode): Page
    {
        $size = self::size($config, $pageSize);

        if ($afterCode !== null) {
            if (! is_string($afterCode) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,29}$/D', $afterCode) !== 1) {
                throw PurchasingRefused::validation('The after_code must be a supplier code: 1 to 30 letters, digits, dot, underscore or hyphen.');
            }

            $builder->where('code', '>', strtoupper($afterCode));
        }

        $rows = $builder->orderBy('code')->limit($size + 1)->get()->all();
        $hasMore = count($rows) > $size;
        $rows = array_slice($rows, 0, $size);

        return new Page(
            self::arrays($rows),
            $hasMore ? (string) ((array) $rows[count($rows) - 1])['code'] : null,
        );
    }

    /**
     * @param  array<int, object>  $rows
     * @return list<array<string, mixed>>
     */
    private static function arrays(array $rows): array
    {
        return array_map(static fn (object $row): array => (array) $row, array_values($rows));
    }
}
