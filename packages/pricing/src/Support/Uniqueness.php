<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Support;

use Flexipos\Pricing\Exceptions\PricingAdminRefused;
use Illuminate\Database\Connection;

/**
 * The case-insensitive `code` pre-check every coded table shares.
 *
 * The unique index is the backstop, and it is bytewise under `ascii_bin`, so
 * `VAT` and `vat` are two index entries. The pre-check compares `lower()` so
 * they are one code: two codes that differ only by case would make the engine's
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
