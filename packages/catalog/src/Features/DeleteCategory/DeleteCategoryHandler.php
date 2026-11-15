<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Features\DeleteCategory;

use Flexipos\Catalog\Exceptions\CatalogRefused;
use Flexipos\Catalog\Registry\CatalogAuditActions;
use Flexipos\Catalog\Support\Identifier;
use Flexipos\Catalog\Support\Lookup;
use Flexipos\Catalog\Support\TenantConnection;
use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Kernel\Schema\Support\RunnerClock;

/**
 * Soft-delete a category (T-027 AC-5, plan Ruling 7).
 *
 * Refused while a live child category, or a live product, still references
 * it; a soft-deleted child or product does not block.
 */
final class DeleteCategoryHandler
{
    public const ACTION = 'catalog_category_deleted';

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly AuditTrail $audit,
    ) {}

    public function handle(DeleteCategory $command): void
    {
        $connection = $this->tenant->get();

        $connection->transaction(function () use ($connection, $command): void {
            $categoryId = Identifier::fold($command->categoryId, 'categoryId');

            $before = Lookup::live($connection, 'categories', $categoryId, 'category', lock: true);

            if ($connection->table('categories')->where('parent_id', $categoryId)->whereNull('deleted_at')->exists()) {
                throw CatalogRefused::inUse("Category [{$categoryId}] still has a live child category.");
            }

            if ($connection->table('products')->where('category_id', $categoryId)->whereNull('deleted_at')->exists()) {
                throw CatalogRefused::inUse("Category [{$categoryId}] is still referenced by a live product.");
            }

            $connection->table('categories')->where('id', $categoryId)->update([
                'deleted_at' => $this->clock->timestamp(),
                'updated_at' => $this->clock->timestamp(),
            ]);

            $after = $connection->table('categories')->where('id', $categoryId)->first();

            $this->audit->record(
                CatalogAuditActions::MODULE,
                $command->actorId,
                self::ACTION,
                'category',
                $categoryId,
                (array) $before,
                $after === null ? null : (array) $after,
            );
        });
    }
}
