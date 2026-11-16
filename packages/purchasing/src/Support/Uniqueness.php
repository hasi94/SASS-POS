<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Support;

use Flexipos\Purchasing\Exceptions\PurchasingRefusal;
use Flexipos\Purchasing\Exceptions\PurchasingRefused;
use Illuminate\Database\Connection;

/**
 * The case-insensitive `code` pre-check a coded table shares (AC-3, AC-9).
 *
 * The unique index is the backstop. It uses the tenant connection's default
 * collation, which ignores case on MySQL and MariaDB and is bytewise on the
 * SQLite stand-in, so whether `ACME-1` and `acme-1` are one index entry depends
 * on the engine. The `lower()` pre-check gives one answer on every engine, and
 * the backstop, when it fires, is caught and refused as a duplicate. A
 * soft-deleted row still holds its code, so the check does not exclude it.
 *
 * The refusal names the **existing** row's id (AC-3), which is also how a
 * retried create is answered (AC-9: supplier ids are server-generated, so a
 * retry is a duplicate code, never a replay).
 */
final class Uniqueness
{
    /**
     * @throws PurchasingRefused duplicate_code naming the holder
     */
    public static function assertCodeFree(Connection $connection, string $table, string $code, ?string $exceptId = null): void
    {
        $query = $connection->table($table)->whereRaw('lower(code) = ?', [strtolower($code)]);

        if ($exceptId !== null) {
            $query->where('id', '!=', $exceptId);
        }

        $holder = $query->value('id');

        if ($holder !== null) {
            throw PurchasingRefused::because(
                PurchasingRefusal::DuplicateCode,
                "The code [{$code}] is already used by [".strtoupper((string) $holder).'].',
            );
        }
    }
}
