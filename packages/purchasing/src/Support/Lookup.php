<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Support;

use Flexipos\Purchasing\Exceptions\PurchasingRefused;
use Illuminate\Database\Connection;

/**
 * Finding one row by id: the existence checks the slices make against their own
 * tables and the reference tables they read as facts (branches, terminals,
 * customers, products).
 *
 * **`live()` excludes soft-deleted rows; `any()` does not.** A table with no
 * `deleted_at` column is read with `any()`.
 *
 * **`$lock`** is `false` (a plain read), `true` (`FOR UPDATE`) or {@see self::SHARED}
 * (`LOCK IN SHARE MODE`). A lock is always taken by primary key, never by a
 * range (Ruling 7, row 1).
 */
final class Lookup
{
    public const SHARED = 'shared';

    /**
     * @throws PurchasingRefused validation (malformed id), notFound
     */
    public static function live(Connection $connection, string $table, mixed $id, string $what, bool|string $lock = false): object
    {
        return self::find($connection, $table, $id, $what, $lock, true);
    }

    /**
     * @throws PurchasingRefused validation (malformed id), notFound
     */
    public static function any(Connection $connection, string $table, mixed $id, string $what, bool|string $lock = false): object
    {
        return self::find($connection, $table, $id, $what, $lock, false);
    }

    private static function find(Connection $connection, string $table, mixed $id, string $what, bool|string $lock, bool $liveOnly): object
    {
        $folded = Identifier::fold($id, $what);

        $query = $connection->table($table)->where('id', $folded);

        if ($liveOnly) {
            $query->whereNull('deleted_at');
        }

        if ($lock === self::SHARED) {
            $query->sharedLock();
        } elseif ($lock === true) {
            $query->lockForUpdate();
        }

        $row = $query->first();

        if ($row === null) {
            throw PurchasingRefused::notFound("No {$what} has id [{$folded}].");
        }

        return $row;
    }
}
