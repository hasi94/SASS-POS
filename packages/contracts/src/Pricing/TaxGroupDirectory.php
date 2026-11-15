<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Pricing;

/**
 * The two questions catalog asks pricing about a tax group (AC-3, D-21a).
 *
 * **Published here so `packages/catalog` never reads `tax_groups`.** "Which
 * group is the default" and "is this group active" are owned by exactly one
 * package; the boundary between the two is this interface, the
 * `Organization\BranchDirectory` shape.
 *
 * The id is compared exactly; a caller folds it to upper case first. The answer
 * is read from the tenant database the process is currently entered into.
 */
interface TaxGroupDirectory
{
    public function state(string $taxGroupId): TaxGroupState;

    /**
     * The one group flagged default and still active, or null when there is
     * none (`CreateProduct` then refuses `no_default_tax_group`).
     */
    public function defaultGroupId(): ?string;
}
