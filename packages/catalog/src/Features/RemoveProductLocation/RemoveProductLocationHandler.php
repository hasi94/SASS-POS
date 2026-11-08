<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Features\RemoveProductLocation;

use Flexipos\Catalog\Exceptions\CatalogRefused;
use Flexipos\Catalog\Registry\CatalogAuditActions;
use Flexipos\Catalog\Support\Identifier;
use Flexipos\Catalog\Support\TenantConnection;
use Flexipos\Contracts\Audit\AuditTrail;

/**
 * Remove a product's per-location stock settings (T-027 AC-32, plan
 * Ruling 9).
 */
final class RemoveProductLocationHandler
{
    public const ACTION = 'catalog_product_location_removed';

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly AuditTrail $audit,
    ) {}

    public function handle(RemoveProductLocation $command): void
    {
        $connection = $this->tenant->get();

        $connection->transaction(function () use ($connection, $command): void {
            $productId = Identifier::fold($command->productId, 'productId');
            $locationId = Identifier::fold($command->locationId, 'locationId');

            $before = $connection->table('product_locations')
                ->where('product_id', $productId)
                ->where('location_id', $locationId)
                ->lockForUpdate()
                ->first();

            if ($before === null) {
                throw CatalogRefused::notFound("Product [{$productId}] has no settings at location [{$locationId}].");
            }

            $connection->table('product_locations')->where('product_id', $productId)->where('location_id', $locationId)->delete();

            $this->audit->record(
                CatalogAuditActions::MODULE,
                $command->actorId,
                self::ACTION,
                'product_location',
                "{$productId}:{$locationId}",
                (array) $before,
                null,
            );
        });
    }
}
