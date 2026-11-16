<?php

declare(strict_types=1);

namespace Flexipos\Purchasing\Documents;

use Flexipos\Contracts\Pricing\TaxGroupDirectory;
use Flexipos\Contracts\Pricing\TaxGroupState;
use Flexipos\Purchasing\Exceptions\PurchasingRefusal;
use Flexipos\Purchasing\Exceptions\PurchasingRefused;
use Flexipos\Purchasing\Support\Identifier;

/**
 * Which tax group a line is priced under (T-034 AC-44, D-42).
 *
 * The chain: the line's **explicit** group, else the product's
 * `purchase_tax_group_id`, else `TaxGroupDirectory::defaultGroupId()`, else
 * `no_default_tax_group`. The chosen group's state is then asked of the
 * directory **before pricing**, because the engine computes an inactive group
 * without complaint: `unknown_tax_group` and `inactive_tax_group` are decided
 * here. A group whose members apply only to sales is not refused; the engine
 * taxes it at zero, which is its own definition (D-42).
 */
final class TaxGroupChain
{
    public function __construct(private readonly TaxGroupDirectory $groups) {}

    /**
     * @param  mixed  $explicit  the line's own `tax_group_id`, null when absent
     * @param  ?string  $productGroupId  the product's `purchase_tax_group_id`
     * @return string the folded id of an active group
     *
     * @throws PurchasingRefused validation (malformed explicit id), no_default_tax_group, unknown_tax_group, inactive_tax_group
     */
    public function resolve(mixed $explicit, ?string $productGroupId, bool $lock = false): string
    {
        $id = Identifier::foldNullable($explicit, 'tax_group_id')
            ?? $productGroupId
            ?? $this->groups->defaultGroupId($lock);

        if ($id === null) {
            throw PurchasingRefused::because(
                PurchasingRefusal::NoDefaultTaxGroup,
                'The line names no tax group, its product has none and the shop has no default tax group.',
            );
        }

        $id = strtoupper($id);

        return match ($this->groups->state($id, $lock)) {
            TaxGroupState::Active => $id,
            TaxGroupState::Inactive => throw PurchasingRefused::because(PurchasingRefusal::InactiveTaxGroup, "Tax group [{$id}] is inactive."),
            TaxGroupState::Unknown => throw PurchasingRefused::because(PurchasingRefusal::UnknownTaxGroup, "No tax group has id [{$id}]."),
        };
    }
}
