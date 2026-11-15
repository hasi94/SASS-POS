<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Support;

use Flexipos\Catalog\Exceptions\CatalogRefused;
use Illuminate\Database\Connection;

/**
 * Finding one row by id, across every catalog table a handler needs to read
 * (plan Ruling 10, section 3: "live and any-row fetchers"). There is no
 * per-entity lookup class here, because none of this task's tables has its
 * own read slice yet (Get/List is FP-240's, Ruling 10) - every handler that
 * needs a row reads it straight off `$table`.
 *
 * **`live()` excludes soft-deleted rows; `any()` does not.** A handler calls
 * `any()` only where the plan says resolution or a reference check must see
 * a soft-deleted row too (for example, `UnitLadder::resolve()`'s own
 * product read, which must still resolve a refund of a retired product -
 * Ruling 3, step 0).
 */
final class Lookup
{
    /**
     * @throws CatalogRefused validation (malformed id), notFound
     */
    public static function live(Connection $connection, string $table, mixed $id, string $what, bool $lock = false): object
    {
        $folded = Identifier::fold($id, $what);

        $query = $connection->table($table)->where('id', $folded)->whereNull('deleted_at');

        if ($lock) {
            $query->lockForUpdate();
        }

        $row = $query->first();

        if ($row === null) {
            throw CatalogRefused::notFound("No {$what} has id [{$folded}].");
        }

        return $row;
    }

    /**
     * @throws CatalogRefused validation (malformed id), notFound
     */
    public static function any(Connection $connection, string $table, mixed $id, string $what, bool $lock = false): object
    {
        $folded = Identifier::fold($id, $what);

        $query = $connection->table($table)->where('id', $folded);

        if ($lock) {
            $query->lockForUpdate();
        }

        $row = $query->first();

        if ($row === null) {
            throw CatalogRefused::notFound("No {$what} has id [{$folded}].");
        }

        return $row;
    }
}
