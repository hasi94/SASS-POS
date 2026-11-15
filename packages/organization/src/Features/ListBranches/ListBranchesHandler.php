<?php

declare(strict_types=1);

namespace Flexipos\Organization\Features\ListBranches;

use Flexipos\Organization\Support\BranchLookup;
use Flexipos\Organization\Support\PageSize;
use Flexipos\Organization\Support\TenantConnection;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * The branches, ordered by `sort_order` then `code`, inactive ones included
 * with their flag, soft-deleted ones excluded, at most one page (T-014 AC-13).
 */
final class ListBranchesHandler
{
    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly ConfigRepository $config,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function handle(ListBranches $query): array
    {
        $rows = $this->tenant->get()->table('branches')
            ->whereNull('deleted_at')
            ->orderBy('sort_order')
            ->orderBy('code')
            ->limit(PageSize::resolve($query->pageSize, $this->config))
            ->get();

        $views = [];

        foreach ($rows as $row) {
            $views[] = BranchLookup::view($row);
        }

        return $views;
    }
}
