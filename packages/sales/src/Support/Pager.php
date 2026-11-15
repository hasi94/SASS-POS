<?php

declare(strict_types=1);

namespace Flexipos\Sales\Support;

use Flexipos\Kernel\Audit\PageSize;
use Flexipos\Sales\Exceptions\SalesRefused;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Query\Builder;

/**
 * The keyset pagers every list in this package uses (T-030 D-26, the customers
 * `Pager` shape): newest-first, `before_id` as the cursor.
 *
 * `sales.page_size` and `sales.max_page_size` are the one authority for the
 * numbers; a larger request is clamped, never refused. A malformed `before_id`
 * is refused.
 */
final class Pager
{
    /**
     * Newest first by the server-generated ULID `id`.
     */
    public static function page(Builder $builder, ConfigRepository $config, ?int $pageSize, ?string $beforeId): Page
    {
        if ($beforeId !== null) {
            $builder->where('id', '<', Identifier::fold($beforeId, 'before_id'));
        }

        $size = self::size($config, $pageSize);

        $rows = $builder->orderByDesc('id')->limit($size + 1)->get()->all();

        return self::slice($rows, $size);
    }

    /**
     * Newest first by `(created_at, id)`, for a table whose ids the caller
     * supplies (R-17): an id alone would order by whatever the client invented.
     * The cursor row is read from this same builder's table, so a cursor naming
     * a row outside the filter is refused rather than silently restarting.
     */
    public static function pageByCreatedAt(Builder $builder, ConfigRepository $config, ?int $pageSize, ?string $beforeId, string $table): Page
    {
        if ($beforeId !== null) {
            $cursor = Identifier::fold($beforeId, 'before_id');

            $createdAt = $builder->getConnection()->table($table)->where('id', $cursor)->value('created_at');

            if ($createdAt === null) {
                throw SalesRefused::validation('The before_id names no row.');
            }

            $builder->where(static function (Builder $either) use ($createdAt, $cursor): void {
                $either->where('created_at', '<', $createdAt)
                    ->orWhere(static function (Builder $tie) use ($createdAt, $cursor): void {
                        $tie->where('created_at', '=', $createdAt)->where('id', '<', $cursor);
                    });
            });
        }

        $size = self::size($config, $pageSize);

        $rows = $builder->orderByDesc('created_at')->orderByDesc('id')->limit($size + 1)->get()->all();

        return self::slice($rows, $size);
    }

    private static function size(ConfigRepository $config, ?int $pageSize): int
    {
        return PageSize::clamp(
            $pageSize,
            (int) $config->get('sales.page_size', 50),
            (int) $config->get('sales.max_page_size', 100),
        );
    }

    /**
     * @param  list<object>  $rows  one more than a page, newest first
     */
    private static function slice(array $rows, int $size): Page
    {
        $hasMore = count($rows) > $size;
        $rows = array_slice($rows, 0, $size);

        $nextBeforeId = $hasMore ? (string) ((array) $rows[count($rows) - 1])['id'] : null;

        return new Page(
            array_map(static fn (object $row): array => (array) $row, $rows),
            $nextBeforeId,
        );
    }
}
