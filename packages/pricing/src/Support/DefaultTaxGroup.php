<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Support;

use Illuminate\Database\Connection;

/**
 * At most one tax group is the default (AC-8): making one group the default
 * clears the previous default in the same transaction, the shape T-027's
 * `product_barcodes.is_primary` and T-028's `customer_groups.is_default` use.
 *
 * The caller must clear **before** it sets, because the unique
 * `default_marker` column is the backstop and refuses two defaults at once.
 * The old default row is locked first (a no-op on SQLite, `[ENGINE]`).
 */
final class DefaultTaxGroup
{
    /**
     * Clear `is_default` on every group except `$exceptId`.
     */
    public static function clearOthers(Connection $connection, ?string $exceptId, string $now): void
    {
        $query = $connection->table('tax_groups')->where('is_default', true)->lockForUpdate();

        if ($exceptId !== null) {
            $query->where('id', '!=', $exceptId);
        }

        $ids = $query->pluck('id')->all();

        if ($ids !== []) {
            $connection->table('tax_groups')->whereIn('id', $ids)->update(['is_default' => false, 'updated_at' => $now]);
        }
    }
}
