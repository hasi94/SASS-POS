<?php

declare(strict_types=1);

namespace Flexipos\Pricing\Taxes;

use Flexipos\Contracts\Pricing\TaxGroupDirectory;
use Flexipos\Contracts\Pricing\TaxGroupState;
use Flexipos\Pricing\Support\TenantConnection;

/**
 * {@see TaxGroupDirectory} over the tenant database (T-029 AC-3, D-21a).
 *
 * A tenant read and nothing else: no tenant predicate (the database is the
 * boundary) and the id compared exactly, the caller having folded it to upper
 * case. `tax_groups` has no soft delete (F-6), so "retired" is `is_active =
 * false` and nothing else.
 *
 * With `$lock` the read takes a shared lock (`LOCK IN SHARE MODE` on MariaDB,
 * `FOR SHARE` on MySQL), the lock the `products` insert's foreign-key check takes
 * on the parent row anyway, only earlier. It conflicts with `UpdateTaxGroup`'s
 * `lockForUpdate()` on the group, which is the race, and it does not serialise
 * product writes against each other. The SQLite stand-in compiles it to nothing:
 * NOT VERIFIED at run time until the engine hold lifts.
 */
final class DatabaseTaxGroupDirectory implements TaxGroupDirectory
{
    public function __construct(private readonly TenantConnection $tenant) {}

    public function state(string $taxGroupId, bool $lock = false): TaxGroupState
    {
        $query = $this->tenant->get()->table('tax_groups')->where('id', $taxGroupId);

        if ($lock) {
            $query->sharedLock();
        }

        $active = $query->value('is_active');

        if ($active === null) {
            return TaxGroupState::Unknown;
        }

        return (bool) $active ? TaxGroupState::Active : TaxGroupState::Inactive;
    }

    /**
     * The one group flagged default that is still active. `default_marker` is
     * unique, so at most one row can match: this is a key lookup, not a pick
     * among candidates. It reads by that key (`default_marker = 1`, generated
     * from `is_default`) and not by `is_default`, which has no index: a locking
     * read locks what it scans, and an unindexed predicate would lock every
     * group for the length of the caller's write.
     */
    public function defaultGroupId(bool $lock = false): ?string
    {
        $query = $this->tenant->get()->table('tax_groups')->where('default_marker', 1)->where('is_active', true);

        if ($lock) {
            $query->sharedLock();
        }

        $id = $query->value('id');

        return $id === null ? null : (string) $id;
    }
}
