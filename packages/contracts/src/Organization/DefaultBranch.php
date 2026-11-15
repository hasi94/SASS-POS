<?php

declare(strict_types=1);

namespace Flexipos\Contracts\Organization;

/**
 * What `packages/platform`'s provisioning asks `packages/organization` to do
 * for a brand-new tenant's one default branch (T-026 Ruling 5, AC-1, AC-26,
 * B-12).
 *
 * **`validate()` needs no connection, by design.** AC-1 checks every field
 * before `reserve_identity` runs - before any row is written, before a
 * tenant connection even exists - so the branch-name and branch-code rules
 * have to be answerable from the submitted strings alone. It runs the same
 * `OrgCode::normalise()` and
 * `Fields::*` checks `CreateBranch` itself would eventually apply, read
 * directly rather than discovered for the first time, several steps and a
 * real database later, when `CreateBranch` refuses.
 *
 * **The 100-character name limit is `branches.name`'s own column width**
 * (B-12, correcting the plan's earlier "150" draft): `CreateBranchHandler`
 * validates it at 100 today, and this contract validates through the same
 * rule rather than inventing a second number.
 */
interface DefaultBranch
{
    /**
     * @throws \Throwable (the organization package's own refusal type) when
     *                    any field is malformed
     */
    public function validate(BranchSeed $seed): void;

    /**
     * The id of the branch with this code, if one already exists in the
     * tenant - idempotent, for a resumed `seed_core` - or a freshly created
     * one via the unchanged `CreateBranch` handler.
     *
     * @return string the branch id
     */
    public function ensure(BranchSeed $seed): string;

    /**
     * Does a live (not soft-deleted) branch with this id exist? For `verify`.
     */
    public function exists(string $branchId): bool;
}
