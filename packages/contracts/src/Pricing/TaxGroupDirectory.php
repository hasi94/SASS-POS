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
 *
 * **`$lock` (W-2, D-39).** A write that depends on the answer, such as a product
 * naming a group, passes `lock: true` from inside its own transaction on the
 * tenant connection. The read then takes a shared lock on the row (and, for the
 * default, on the key it scans), which conflicts with a concurrent deactivation
 * of the group and returns the latest committed state whatever snapshot the
 * caller's transaction already fixed. **The lock is meaningful only inside the
 * caller's open transaction**: both sides resolve the same tenant connection, so
 * the lock joins that transaction and is held until it commits. Outside one, it
 * is released at once. A caller that passes nothing gets the plain read, with the
 * same answers.
 */
interface TaxGroupDirectory
{
    public function state(string $taxGroupId, bool $lock = false): TaxGroupState;

    /**
     * The one group flagged default and still active, or null when there is
     * none (`CreateProduct` then refuses `no_default_tax_group`).
     */
    public function defaultGroupId(bool $lock = false): ?string;
}
