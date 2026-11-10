<?php

declare(strict_types=1);

namespace Flexipos\Customers\Support;

use Flexipos\Customers\Exceptions\CustomersRefused;
use Illuminate\Database\Connection;

/**
 * Finding one row by id, across every customers table a handler needs to
 * read (T-028 plan Ruling 1, the eighth copy, FP-331).
 *
 * **`live()` excludes soft-deleted rows; `any()` does not.**
 */
final class Lookup
{
    /**
     * @throws CustomersRefused validation (malformed id), notFound
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
            throw CustomersRefused::notFound("No {$what} has id [{$folded}].");
        }

        return $row;
    }

    /**
     * @throws CustomersRefused validation (malformed id), notFound
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
            throw CustomersRefused::notFound("No {$what} has id [{$folded}].");
        }

        return $row;
    }
}
