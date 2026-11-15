<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Support;

use Flexipos\Catalog\Exceptions\CatalogRefused;
use Flexipos\Contracts\Pricing\TaxGroupDirectory;
use Flexipos\Contracts\Pricing\TaxGroupState;

/**
 * What a product's tax group columns may hold (T-029 AC-3, F-18).
 *
 * **Catalog never reads `tax_groups`.** Whether a group exists, is active, or is
 * the default is owned by pricing and asked through the published
 * {@see TaxGroupDirectory}. The ids given here are folded to upper case before
 * the call, because the directory compares exactly (K-10).
 */
final class TaxGroups
{
    /**
     * @throws CatalogRefused unknownTaxGroup, inactiveTaxGroup
     */
    public static function assertUsable(TaxGroupDirectory $directory, string $taxGroupId, string $field): void
    {
        match ($directory->state($taxGroupId)) {
            TaxGroupState::Active => null,
            TaxGroupState::Unknown => throw CatalogRefused::unknownTaxGroup("The {$field} [{$taxGroupId}] is not a tax group."),
            TaxGroupState::Inactive => throw CatalogRefused::inactiveTaxGroup("The {$field} [{$taxGroupId}] is an inactive tax group."),
        };
    }

    /**
     * The tax group a new product is written with: the one named, or the
     * tenant's default when none was. A tenant with no default refuses rather
     * than write the null the application-level `NOT NULL` exists to prevent.
     *
     * @throws CatalogRefused unknownTaxGroup, inactiveTaxGroup, noDefaultTaxGroup
     */
    public static function resolveForCreate(TaxGroupDirectory $directory, ?string $named): string
    {
        if ($named !== null) {
            self::assertUsable($directory, $named, 'tax_group_id');

            return $named;
        }

        $default = $directory->defaultGroupId();

        if ($default === null) {
            throw CatalogRefused::noDefaultTaxGroup('No tax_group_id was given and the tenant has no active default tax group.');
        }

        return strtoupper($default);
    }
}
