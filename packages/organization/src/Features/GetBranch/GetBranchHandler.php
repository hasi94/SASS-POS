<?php

declare(strict_types=1);

namespace Flexipos\Organization\Features\GetBranch;

use Flexipos\Organization\Support\BranchLookup;
use Flexipos\Organization\Support\TenantConnection;

/**
 * One branch's fields and its default location id (T-014 AC-13).
 *
 * A missing id, a soft-deleted row and another tenant's well-formed id are
 * the same `not_found`; a lower-case spelling resolves (AC-61).
 */
final class GetBranchHandler
{
    public function __construct(private readonly TenantConnection $tenant) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(GetBranch $query): array
    {
        return BranchLookup::view(BranchLookup::find($this->tenant->get(), $query->branchId));
    }
}
