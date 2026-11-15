<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Features\UpdateProductBarcode;

use Flexipos\Catalog\Exceptions\CatalogRefused;
use Flexipos\Catalog\Registry\CatalogAuditActions;
use Flexipos\Catalog\Support\Fields;
use Flexipos\Catalog\Support\Identifier;
use Flexipos\Catalog\Support\TenantConnection;
use Flexipos\Contracts\Audit\AuditTrail;
use Illuminate\Database\Connection;

/**
 * Update a barcode's `unit_id`, `quantity` or `is_primary` (T-027 AC-29,
 * AC-30, plan Ruling 9). The `barcode` value itself does not appear in
 * {@see self::FIELDS}: it is immutable, and changing it means remove plus
 * re-add.
 */
final class UpdateProductBarcodeHandler
{
    public const ACTION = 'catalog_product_barcode_updated';

    public const FIELDS = ['unit_id', 'quantity', 'is_primary'];

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly AuditTrail $audit,
    ) {}

    public function handle(UpdateProductBarcode $command): void
    {
        $fields = $command->fields;

        Fields::only($fields, self::FIELDS);

        if ($fields === []) {
            throw CatalogRefused::validation('An update must name at least one field.');
        }

        $barcodeId = Identifier::fold($command->barcodeId, 'barcodeId');

        $changes = [];

        if (Fields::has($fields, 'unit_id')) {
            $changes['unit_id'] = Identifier::foldNullable($fields['unit_id'], 'unit_id');
        }

        if (Fields::has($fields, 'quantity')) {
            $quantity = Fields::decimal($fields, 'quantity', 4);

            if ($quantity === null || (float) $quantity <= 0.0) {
                throw CatalogRefused::validation('The quantity must be greater than 0.');
            }

            $changes['quantity'] = $quantity;
        }

        if (Fields::has($fields, 'is_primary')) {
            $changes['is_primary'] = Fields::flag($fields, 'is_primary', false);
        }

        $connection = $this->tenant->get();

        $connection->transaction(function () use ($connection, $command, $barcodeId, $changes): void {
            $before = $connection->table('product_barcodes')->where('id', $barcodeId)->lockForUpdate()->first();

            if ($before === null) {
                throw CatalogRefused::notFound("The product barcode [{$barcodeId}] does not exist.");
            }

            if (array_key_exists('unit_id', $changes) && $changes['unit_id'] !== null) {
                self::checkUnitDimension($connection, $changes['unit_id'], (string) $before->product_id);
            }

            if (($changes['is_primary'] ?? false) === true) {
                $connection->table('product_barcodes')
                    ->where('product_id', $before->product_id)
                    ->where('id', '!=', $barcodeId)
                    ->where('is_primary', true)
                    ->update(['is_primary' => false]);
            }

            $connection->table('product_barcodes')->where('id', $barcodeId)->update($changes);

            $after = $connection->table('product_barcodes')->where('id', $barcodeId)->first();

            $this->audit->record(
                CatalogAuditActions::MODULE,
                $command->actorId,
                self::ACTION,
                'product_barcode',
                $barcodeId,
                (array) $before,
                $after === null ? null : (array) $after,
            );
        });
    }

    /**
     * @throws CatalogRefused notFound, dimensionMismatch
     */
    private static function checkUnitDimension(Connection $connection, string $unitId, string $productId): void
    {
        $unit = $connection->table('units')->where('id', $unitId)->first();

        if ($unit === null) {
            throw CatalogRefused::notFound("No unit has id [{$unitId}] (unit_id).");
        }

        $product = $connection->table('products')->where('id', $productId)->first(['stock_unit_id']);
        $stockUnit = $product === null ? null : $connection->table('units')->where('id', $product->stock_unit_id)->first();

        if ((string) $unit->dimension !== (string) ($stockUnit === null ? null : $stockUnit->dimension)) {
            throw CatalogRefused::dimensionMismatch("The unit_id [{$unitId}] ({$unit->dimension}) does not share the product's stock unit dimension.");
        }
    }
}
