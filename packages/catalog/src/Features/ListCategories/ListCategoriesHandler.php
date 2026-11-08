<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Features\ListCategories;

use Flexipos\Catalog\Support\TenantConnection;

/**
 * List every live category, ordered by `path` (T-027 plan Ruling 7).
 */
final class ListCategoriesHandler
{
    public function __construct(
        private readonly TenantConnection $tenant,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function handle(ListCategories $query): array
    {
        return $this->tenant->get()->table('categories')
            ->whereNull('deleted_at')
            ->orderBy('path')
            ->get()
            ->map(static fn (object $row): array => (array) $row)
            ->all();
    }
}
