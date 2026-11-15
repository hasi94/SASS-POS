<?php

declare(strict_types=1);

namespace Flexipos\Customers\Support;

use Flexipos\Kernel\Audit\PageSize;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Query\Builder;

/**
 * The one keyset pager every list in this package uses (T-028 plan
 * Ruling 11): newest-first by the ULID `id`, `where id < :before_id`.
 *
 * `customers.page_size`/`max_page_size` are the one authority for the
 * numbers (D-17); a larger request is clamped, never refused. A malformed
 * `before_id` is refused.
 */
final class Pager
{
    public static function page(Builder $builder, ConfigRepository $config, ?int $pageSize, ?string $beforeId): Page
    {
        if ($beforeId !== null) {
            $folded = Identifier::fold($beforeId, 'before_id');
            $builder->where('id', '<', $folded);
        }

        $size = PageSize::clamp(
            $pageSize,
            (int) $config->get('customers.page_size', 50),
            (int) $config->get('customers.max_page_size', 100),
        );

        $rows = $builder->orderByDesc('id')->limit($size + 1)->get()->all();

        $hasMore = count($rows) > $size;
        $rows = array_slice($rows, 0, $size);

        $nextBeforeId = $hasMore ? (string) ((array) $rows[count($rows) - 1])['id'] : null;

        return new Page(
            array_map(static fn (object $row): array => (array) $row, $rows),
            $nextBeforeId,
        );
    }
}
