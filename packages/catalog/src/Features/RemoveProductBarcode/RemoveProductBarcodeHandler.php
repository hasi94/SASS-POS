<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Features\RemoveProductBarcode;

use Flexipos\Catalog\Exceptions\CatalogRefused;
use Flexipos\Catalog\Registry\CatalogAuditActions;
use Flexipos\Catalog\Support\Identifier;
use Flexipos\Catalog\Support\TenantConnection;
use Flexipos\Contracts\Audit\AuditTrail;

/**
 * Remove a product barcode (T-027 AC-28, AC-31, plan Ruling 9).
 */
final class RemoveProductBarcodeHandler
{
    public const ACTION = 'catalog_product_barcode_removed';

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly AuditTrail $audit,
    ) {}

    public function handle(RemoveProductBarcode $command): void
    {
        $connection = $this->tenant->get();

        $connection->transaction(function () use ($connection, $command): void {
            $barcodeId = Identifier::fold($command->barcodeId, 'barcodeId');

            $before = $connection->table('product_barcodes')->where('id', $barcodeId)->lockForUpdate()->first();

            if ($before === null) {
                throw CatalogRefused::notFound("The product barcode [{$barcodeId}] does not exist.");
            }

            $connection->table('product_barcodes')->where('id', $barcodeId)->delete();

            $this->audit->record(
                CatalogAuditActions::MODULE,
                $command->actorId,
                self::ACTION,
                'product_barcode',
                $barcodeId,
                (array) $before,
                null,
            );
        });
    }
}
