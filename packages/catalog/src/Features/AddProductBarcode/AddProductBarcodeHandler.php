<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Features\AddProductBarcode;

use Flexipos\Catalog\Exceptions\CatalogRefused;
use Flexipos\Catalog\Registry\CatalogAuditActions;
use Flexipos\Catalog\Support\Fields;
use Flexipos\Catalog\Support\Identifier;
use Flexipos\Catalog\Support\Lookup;
use Flexipos\Catalog\Support\TenantConnection;
use Flexipos\Contracts\Audit\AuditTrail;
use Illuminate\Database\Connection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * Add an exact-match barcode to a product (T-027 AC-28 to AC-30, plan
 * Ruling 9).
 *
 * **AC-29.** `unit_id` is optional - null means "scans as the product's own
 * stock unit" - and when supplied must share the product's `stock_unit_id`
 * dimension, the same dimension-only check `CreateProduct`'s purchase and
 * sales units take (F-3/D-14): resolvability is a document-time concern, not
 * a write-time one.
 *
 * **AC-30.** Setting `is_primary` clears the product's other primary row in
 * the same transaction. `product_barcodes.primary_product_id`'s unique index
 * is the backstop: if the clearing step were ever skipped, the second insert
 * would throw {@see UniqueConstraintViolationException}, caught below and
 * turned into a refusal rather than a crash.
 */
final class AddProductBarcodeHandler
{
    public const ACTION = 'catalog_product_barcode_added';

    public const FIELDS = ['product_id', 'barcode', 'unit_id', 'quantity', 'is_primary'];

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly AuditTrail $audit,
    ) {}

    public function handle(AddProductBarcode $command): AddProductBarcodeResult
    {
        $fields = $command->fields;

        Fields::only($fields, self::FIELDS);

        $productId = Identifier::fold($fields['product_id'] ?? null, 'product_id');
        $barcode = Fields::requiredText($fields, 'barcode', 64);
        $unitId = Identifier::foldNullable(Fields::has($fields, 'unit_id') ? $fields['unit_id'] : null, 'unit_id');
        $quantity = Fields::decimal($fields, 'quantity', 4, false, '1.0000');
        $isPrimary = Fields::flag($fields, 'is_primary', false);

        if ($quantity === null || (float) $quantity <= 0.0) {
            throw CatalogRefused::validation('The quantity must be greater than 0.');
        }

        $connection = $this->tenant->get();

        return $connection->transaction(function () use ($connection, $command, $productId, $barcode, $unitId, $quantity, $isPrimary): AddProductBarcodeResult {
            $product = Lookup::live($connection, 'products', $productId, 'product', lock: true);

            if ($unitId !== null) {
                self::checkUnitDimension($connection, $unitId, (string) $product->stock_unit_id);
            }

            self::checkDuplicate($connection, $barcode);

            if ($isPrimary) {
                $connection->table('product_barcodes')->where('product_id', $productId)->where('is_primary', true)->update(['is_primary' => false]);
            }

            $barcodeId = (string) Str::ulid();

            try {
                $connection->table('product_barcodes')->insert([
                    'id' => $barcodeId,
                    'product_id' => $productId,
                    'barcode' => $barcode,
                    'unit_id' => $unitId,
                    'quantity' => $quantity,
                    'is_primary' => $isPrimary,
                ]);
            } catch (UniqueConstraintViolationException) {
                self::checkDuplicate($connection, $barcode);

                throw CatalogRefused::duplicate("A barcode [{$barcode}] could not be added: the product's primary barcode was not cleared in time.");
            }

            $row = $connection->table('product_barcodes')->where('id', $barcodeId)->first();

            $this->audit->record(
                CatalogAuditActions::MODULE,
                $command->actorId,
                self::ACTION,
                'product_barcode',
                $barcodeId,
                null,
                $row === null ? null : (array) $row,
            );

            return new AddProductBarcodeResult($barcodeId);
        });
    }

    /**
     * @throws CatalogRefused duplicate
     */
    private static function checkDuplicate(Connection $connection, string $barcode): void
    {
        $existing = $connection->table('product_barcodes')->whereRaw('lower(barcode) = ?', [strtolower($barcode)])->first();

        if ($existing === null) {
            return;
        }

        $owner = $connection->table('products')->where('id', $existing->product_id)->first(['sku']);

        throw CatalogRefused::duplicate("The barcode [{$barcode}] already belongs to product [".($owner === null ? (string) $existing->product_id : $owner->sku).'].');
    }

    /**
     * @throws CatalogRefused notFound, dimensionMismatch
     */
    private static function checkUnitDimension(Connection $connection, string $unitId, string $stockUnitId): void
    {
        $unit = $connection->table('units')->where('id', $unitId)->first();

        if ($unit === null) {
            throw CatalogRefused::notFound("No unit has id [{$unitId}] (unit_id).");
        }

        $stockUnit = $connection->table('units')->where('id', $stockUnitId)->first();

        if ((string) $unit->dimension !== (string) ($stockUnit === null ? null : $stockUnit->dimension)) {
            throw CatalogRefused::dimensionMismatch("The unit_id [{$unitId}] ({$unit->dimension}) does not share the product's stock unit dimension.");
        }
    }
}
