<?php

declare(strict_types=1);

namespace Flexipos\Organization\Support;

use Flexipos\Contracts\Organization\BranchDirectory;
use Flexipos\Contracts\Organization\BranchState;

/**
 * {@see BranchDirectory} over the tenant database (T-014 AC-52).
 *
 * A tenant read and nothing else: no tenant predicate (the database is the
 * boundary), the id compared exactly, and a soft-deleted row counted as
 * absent - nothing in T-014 deletes a branch, but a fixture or a later task
 * might, and "no live branch" is `unknown_branch`.
 */
final class DatabaseBranchDirectory implements BranchDirectory
{
    public function __construct(private readonly TenantConnection $tenant) {}

    public function state(string $branchId): BranchState
    {
        $active = $this->tenant->get()->table('branches')
            ->where('id', $branchId)
            ->whereNull('deleted_at')
            ->value('is_active');

        if ($active === null) {
            return BranchState::Unknown;
        }

        return (bool) $active ? BranchState::Active : BranchState::Inactive;
    }
}
