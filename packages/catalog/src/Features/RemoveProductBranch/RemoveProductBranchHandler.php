<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Features\RemoveProductBranch;

use Flexipos\Catalog\Exceptions\CatalogRefused;
use Flexipos\Catalog\Registry\CatalogAuditActions;
use Flexipos\Catalog\Support\Identifier;
use Flexipos\Catalog\Support\TenantConnection;
use Flexipos\Contracts\Audit\AuditTrail;

/**
 * Remove a product's per-branch selling-price override (T-027 AC-33, plan
 * Ruling 9).
 */
final class RemoveProductBranchHandler
{
    public const ACTION = 'catalog_product_branch_removed';

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly AuditTrail $audit,
    ) {}

    public function handle(RemoveProductBranch $command): void
    {
        $connection = $this->tenant->get();

        $connection->transaction(function () use ($connection, $command): void {
            $productId = Identifier::fold($command->productId, 'productId');
            $branchId = Identifier::fold($command->branchId, 'branchId');

            $before = $connection->table('product_branches')
                ->where('product_id', $productId)
                ->where('branch_id', $branchId)
                ->lockForUpdate()
                ->first();

            if ($before === null) {
                throw CatalogRefused::notFound("Product [{$productId}] has no settings at branch [{$branchId}].");
            }

            $connection->table('product_branches')->where('product_id', $productId)->where('branch_id', $branchId)->delete();

            $this->audit->record(
                CatalogAuditActions::MODULE,
                $command->actorId,
                self::ACTION,
                'product_branch',
                "{$productId}:{$branchId}",
                (array) $before,
                null,
            );
        });
    }
}
