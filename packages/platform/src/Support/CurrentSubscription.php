<?php

declare(strict_types=1);

namespace Flexipos\Platform\Support;

use Illuminate\Database\Connection;

/**
 * "Current subscription" for every rule in T-016's subscription section
 * (AC-14, Ruling 5).
 *
 * The rule: the latest non-null `current_period_start` wins; ties go to the
 * highest `id`; a null ranks below any dated row; among nulls, the highest
 * `id` wins. Decided in PHP, never with `ORDER BY`, so it does not depend on
 * how each engine orders nulls.
 */
final class CurrentSubscription
{
    /**
     * @param  list<object>  $rows
     */
    public static function pick(array $rows): ?object
    {
        if ($rows === []) {
            return null;
        }

        $dated = array_values(array_filter($rows, static fn (object $row): bool => $row->current_period_start !== null));
        $undated = array_values(array_filter($rows, static fn (object $row): bool => $row->current_period_start === null));

        $pool = $dated !== [] ? $dated : $undated;

        usort($pool, static function (object $a, object $b): int {
            if ($a->current_period_start !== $b->current_period_start) {
                return strcmp((string) $a->current_period_start, (string) $b->current_period_start);
            }

            return strcmp((string) $a->id, (string) $b->id);
        });

        return end($pool) ?: null;
    }

    public static function for(Connection $master, string $tenantId): ?object
    {
        $rows = $master->table('subscriptions')->where('tenant_id', $tenantId)->get()->all();

        return self::pick($rows);
    }
}
