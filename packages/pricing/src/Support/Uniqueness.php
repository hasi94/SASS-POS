<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Support;

use Flexipos\Pricing\Exceptions\PricingAdminRefused;
use Illuminate\Database\Connection;

/**
 * The case-insensitive `code` pre-check every coded table shares.
 *
 * The unique index is the backstop. It uses the tenant connection's default
 * collation (`utf8mb4_unicode_ci` in `config/database.php`), which ignores case
 * and accents on MySQL and MariaDB and is bytewise on the SQLite stand-in, so
 * whether `VAT` and `vat` are one index entry depends on the engine. The
 * `lower()` pre-check gives one answer on every engine, and the backstop, when it
 * fires, is caught and refused as a duplicate. The pre-check treats `VAT` and
 * `vat` as one code: two codes that differ only by case would make the engine's
 * bytewise tiebreak (D-29) ambiguous to a human reading a list. A soft-deleted
 * row still holds its code, so the check does not exclude it.
 */
final class Uniqueness
{
    /**
     * @throws PricingAdminRefused duplicate
     */
    public static function assertCodeFree(Connection $connection, string $table, string $code, ?string $exceptId = null): void
    {
        $query = $connection->table($table)->whereRaw('lower(code) = ?', [strtolower($code)]);

        if ($exceptId !== null) {
            $query->where('id', '!=', $exceptId);
        }

        if ($query->exists()) {
            throw PricingAdminRefused::duplicate("The code [{$code}] is already in use.");
        }
    }
}
