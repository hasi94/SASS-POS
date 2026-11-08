<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Features\DeleteBrand;

use Flexipos\Catalog\Exceptions\CatalogRefused;
use Flexipos\Catalog\Registry\CatalogAuditActions;
use Flexipos\Catalog\Support\Identifier;
use Flexipos\Catalog\Support\Lookup;
use Flexipos\Catalog\Support\TenantConnection;
use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Kernel\Schema\Support\RunnerClock;

/**
 * Soft-delete a brand (T-027 AC-8, plan Ruling 10).
 *
 * Refused while a live product still references it; a soft-deleted product
 * does not block.
 */
final class DeleteBrandHandler
{
    public const ACTION = 'catalog_brand_deleted';

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly AuditTrail $audit,
    ) {}

    public function handle(DeleteBrand $command): void
    {
        $connection = $this->tenant->get();

        $connection->transaction(function () use ($connection, $command): void {
            $brandId = Identifier::fold($command->brandId, 'brandId');

            $before = Lookup::live($connection, 'brands', $brandId, 'brand', lock: true);

            if ($connection->table('products')->where('brand_id', $brandId)->whereNull('deleted_at')->exists()) {
                throw CatalogRefused::inUse("Brand [{$brandId}] is still referenced by a live product.");
            }

            $connection->table('brands')->where('id', $brandId)->update([
                'deleted_at' => $this->clock->timestamp(),
                'updated_at' => $this->clock->timestamp(),
            ]);

            $after = $connection->table('brands')->where('id', $brandId)->first();

            $this->audit->record(
                CatalogAuditActions::MODULE,
                $command->actorId,
                self::ACTION,
                'brand',
                $brandId,
                (array) $before,
                $after === null ? null : (array) $after,
            );
        });
    }
}
