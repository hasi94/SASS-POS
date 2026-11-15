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
 */
final class DatabaseTaxGroupDirectory implements TaxGroupDirectory
{
    public function __construct(private readonly TenantConnection $tenant) {}

    public function state(string $taxGroupId): TaxGroupState
    {
        $active = $this->tenant->get()->table('tax_groups')->where('id', $taxGroupId)->value('is_active');

        if ($active === null) {
            return TaxGroupState::Unknown;
        }

        return (bool) $active ? TaxGroupState::Active : TaxGroupState::Inactive;
    }

    /**
     * The one group flagged default that is still active. `default_marker` is
     * unique, so at most one row can match: this is a key lookup, not a pick
     * among candidates.
     */
    public function defaultGroupId(): ?string
    {
        $id = $this->tenant->get()->table('tax_groups')->where('is_default', true)->where('is_active', true)->value('id');

        return $id === null ? null : (string) $id;
    }
}
