<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Support;

use Flexipos\Pricing\Exceptions\PricingAdminRefused;
use Illuminate\Database\Connection;

/**
 * Finding one row by id: the existence checks the CRUD slices make against
 * their own tables and against the reference tables they read without a key
 * (branches, customer groups, customers, products, workflow definitions). This
 * is the write path; the pricing read path is a different class and never uses
 * it.
 *
 * **`live()` excludes soft-deleted rows; `any()` does not.** A table with no
 * `deleted_at` column is read with `any()`.
 */
final class Lookup
{
    /**
     * @throws PricingAdminRefused validation (malformed id), notFound
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
            throw PricingAdminRefused::notFound("No {$what} has id [{$folded}].");
        }

        return $row;
    }

    /**
     * @throws PricingAdminRefused validation (malformed id), notFound
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
            throw PricingAdminRefused::notFound("No {$what} has id [{$folded}].");
        }

        return $row;
    }
}
