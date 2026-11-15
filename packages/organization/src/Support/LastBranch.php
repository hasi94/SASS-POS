<?php

declare(strict_types=1);

namespace Flexipos\Organization\Support;

use Flexipos\Organization\Exceptions\OrganizationRefused;
use Illuminate\Database\Connection;

/**
 * A tenant never loses its last active branch (T-014 AC-14, AC-16).
 *
 * The same shape as access's `LastOwner`, and for the same reason: the count
 * and the write must not be separable by another request. **The active
 * branches are read `for update`, the one being deactivated included**, so
 * two requests deactivating the last two branches queue on the same rows
 * instead of each seeing the other as "the one that remains". Excluding the
 * branch itself from the read would let the two lock disjoint rows.
 *
 * On the SQLite stand-in the lock clause is a no-op, so the clause is shown
 * on MySQL's grammar through `pretend()` (a test) and the concurrent half is
 * `[ENGINE]`, NOT VERIFIED while the hold stands.
 */
final class LastBranch
{
    /**
     * @throws OrganizationRefused last_branch
     */
    public static function requireAnother(Connection $connection, string $branchId): void
    {
        $active = $connection->table('branches')
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->lockForUpdate()
            ->pluck('id')
            ->map(static fn ($id): string => (string) $id)
            ->all();

        $others = array_values(array_filter($active, static fn (string $id): bool => $id !== $branchId));

        if ($others === []) {
            throw OrganizationRefused::lastBranch("Branch [{$branchId}] is the only active branch.");
        }
    }
}
