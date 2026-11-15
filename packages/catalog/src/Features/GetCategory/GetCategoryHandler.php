<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Features\GetCategory;

use Flexipos\Catalog\Support\Identifier;
use Flexipos\Catalog\Support\Lookup;
use Flexipos\Catalog\Support\TenantConnection;

/**
 * Read one category by id, soft-deleted or not (T-027 plan Ruling 7).
 */
final class GetCategoryHandler
{
    public function __construct(
        private readonly TenantConnection $tenant,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(GetCategory $query): array
    {
        $connection = $this->tenant->get();
        $categoryId = Identifier::fold($query->categoryId, 'categoryId');

        $row = Lookup::any($connection, 'categories', $categoryId, 'category');

        return (array) $row;
    }
}
