<?php

declare(strict_types=1);

namespace Flexipos\Sales\Support;

use Flexipos\Sales\Exceptions\SalesRefused;
use Illuminate\Database\Connection;

/**
 * The case-insensitive `code` pre-check every coded table shares (the pricing
 * precedent).
 *
 * The unique index is the backstop. It uses the tenant connection's default
 * collation, which ignores case on MySQL and MariaDB and is bytewise on the
 * SQLite stand-in, so whether `CASH` and `cash` are one index entry depends on
 * the engine. The `lower()` pre-check gives one answer on every engine, and the
 * backstop, when it fires, is caught and refused as a duplicate. A soft-deleted
 * row still holds its code, so the check does not exclude it.
 */
final class Uniqueness
{
    /**
     * @throws SalesRefused duplicate
     */
    public static function assertCodeFree(Connection $connection, string $table, string $code, ?string $exceptId = null): void
    {
        $query = $connection->table($table)->whereRaw('lower(code) = ?', [strtolower($code)]);

        if ($exceptId !== null) {
            $query->where('id', '!=', $exceptId);
        }

        if ($query->exists()) {
            throw SalesRefused::duplicate("The code [{$code}] is already in use.");
        }
    }
}
