<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Features\DeleteProduct;

use Flexipos\Catalog\Exceptions\CatalogRefused;
use Flexipos\Catalog\Registry\CatalogAuditActions;
use Flexipos\Catalog\Support\Identifier;
use Flexipos\Catalog\Support\Lookup;
use Flexipos\Catalog\Support\TenantConnection;
use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Kernel\Schema\Support\RunnerClock;

/**
 * Soft-delete a product (T-027 AC-20, plan Ruling 8, section 0.2's reading
 * of F-4).
 *
 * Refused while:
 * - it is a `variant_parent` with a live variant;
 * - it is the component of a product whose parent is not soft-deleted
 *   (`product_components` has no `[SOFT]` column of its own, so "live"
 *   reads as "the parent product is not soft-deleted" - plan section 0.2);
 * - it is the linked product of a modifier.
 */
final class DeleteProductHandler
{
    public const ACTION = 'catalog_product_deleted';

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly AuditTrail $audit,
    ) {}

    public function handle(DeleteProduct $command): void
    {
        $connection = $this->tenant->get();

        $connection->transaction(function () use ($connection, $command): void {
            $productId = Identifier::fold($command->productId, 'productId');

            $before = Lookup::live($connection, 'products', $productId, 'product', lock: true);

            if ($before->type === 'variant_parent' && $connection->table('products')->where('parent_id', $productId)->whereNull('deleted_at')->exists()) {
                throw CatalogRefused::inUse("Product [{$productId}] has a live variant.");
            }

            $isLiveComponent = $connection->table('product_components')
                ->join('products', 'products.id', '=', 'product_components.parent_product_id')
                ->where('product_components.component_product_id', $productId)
                ->whereNull('products.deleted_at')
                ->exists();

            if ($isLiveComponent) {
                throw CatalogRefused::inUse("Product [{$productId}] is still the component of a live product.");
            }

            if ($connection->table('modifiers')->where('linked_product_id', $productId)->exists()) {
                throw CatalogRefused::inUse("Product [{$productId}] is still the linked product of a modifier.");
            }

            $connection->table('products')->where('id', $productId)->update([
                'deleted_at' => $this->clock->timestamp(),
                'updated_at' => $this->clock->timestamp(),
                'updated_by' => $command->actorId,
            ]);

            $after = $connection->table('products')->where('id', $productId)->first();

            $this->audit->record(
                CatalogAuditActions::MODULE,
                $command->actorId,
                self::ACTION,
                'product',
                $productId,
                (array) $before,
                $after === null ? null : (array) $after,
            );
        });
    }
}
