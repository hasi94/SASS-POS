<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Organization;

/**
 * The one question access asks organization about a branch (T-014 AC-52).
 *
 * **Published here so `packages/access` never queries `branches` and never
 * imports an organization class.** Access holds the branch-scoped grants and
 * organization holds the branches; the boundary between the two packages is
 * this method, and the deptrac ruleset has no edge between them.
 *
 * The id is compared exactly. A caller folds it to upper case first
 * (`BranchScope::requireWellFormed()`), because the stored ids are upper-case
 * and a comparison that folded for itself would hide a caller that did not.
 *
 * The answer is read from the tenant database the process is currently
 * entered into, and from nowhere else.
 */
interface BranchDirectory
{
    public function state(string $branchId): BranchState;
}
