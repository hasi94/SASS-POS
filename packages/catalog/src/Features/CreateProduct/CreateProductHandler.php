<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Features\CreateProduct;

use Flexipos\Catalog\Exceptions\CatalogRefused;
use Flexipos\Catalog\Products\PriceMode;
use Flexipos\Catalog\Products\ProductRules;
use Flexipos\Catalog\Products\ProductType;
use Flexipos\Catalog\Products\TrackingMode;
use Flexipos\Catalog\Registry\CatalogAuditActions;
use Flexipos\Catalog\Support\Fields;
use Flexipos\Catalog\Support\Identifier;
use Flexipos\Catalog\Support\Lookup;
use Flexipos\Catalog\Support\Money;
use Flexipos\Catalog\Support\TenantConnection;
use Flexipos\Contracts\Audit\AuditTrail;
use Flexipos\Kernel\Schema\Support\RunnerClock;
use Illuminate\Database\Connection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * Create a product (T-027 AC-18 to AC-27, AC-27a, plan Ruling 8).
 *
 * **`custom_fields` is refused as an unknown field** (AC-27a, D-12): it is
 * not in {@see self::FIELDS}, so `Fields::only()` refuses it before
 * anything else runs - exactly the same refusal path as any other field
 * this operation does not take.
 */
final class CreateProductHandler
{
    public const ACTION = 'catalog_product_created';

    public const FIELDS = [
        'type', 'parent_id', 'sku', 'name', 'short_name', 'description', 'category_id', 'brand_id',
        'stock_unit_id', 'purchase_unit_id', 'sales_unit_id', 'cost_price', 'selling_price',
        'min_selling_price', 'msrp', 'track_stock', 'tracking_mode', 'is_weighable', 'price_mode',
        'plu_code', 'shelf_life_days', 'is_sellable', 'is_purchasable', 'is_active', 'allow_price_override',
        'allow_discount', 'weight', 'dimensions', 'variant_options', 'variant_values', 'image_path', 'sort_order',
    ];

    public function __construct(
        private readonly TenantConnection $tenant,
        private readonly RunnerClock $clock,
        private readonly AuditTrail $audit,
    ) {}

    public function handle(CreateProduct $command): CreateProductResult
    {
        $fields = $command->fields;

        Fields::only($fields, self::FIELDS);

        $type = self::type($fields);
        $sku = Fields::requiredText($fields, 'sku', 50);
        $name = Fields::requiredText($fields, 'name', 150);
        $shortName = Fields::optionalText($fields, 'short_name', 40);
        $description = Fields::optionalText($fields, 'description', 65535);
        $categoryId = Identifier::fold($fields['category_id'] ?? null, 'category_id');
        $brandId = Identifier::foldNullable(Fields::has($fields, 'brand_id') ? $fields['brand_id'] : null, 'brand_id');
        $stockUnitId = Identifier::fold($fields['stock_unit_id'] ?? null, 'stock_unit_id');
        $purchaseUnitId = Identifier::foldNullable(Fields::has($fields, 'purchase_unit_id') ? $fields['purchase_unit_id'] : null, 'purchase_unit_id');
        $salesUnitId = Identifier::foldNullable(Fields::has($fields, 'sales_unit_id') ? $fields['sales_unit_id'] : null, 'sales_unit_id');
        $costPrice = Money::amount($fields, 'cost_price', false, '0.0000');
        $sellingPrice = Money::amount($fields, 'selling_price', false, '0.0000');
        $minSellingPrice = Money::amount($fields, 'min_selling_price', false, null);
        $msrp = Money::amount($fields, 'msrp', false, null);
        $trackStock = Fields::flag($fields, 'track_stock', true);
        $trackingMode = self::enumField($fields, 'tracking_mode', TrackingMode::class, TrackingMode::Quantity);
        $isWeighable = Fields::flag($fields, 'is_weighable', false);
        $priceMode = self::enumField($fields, 'price_mode', PriceMode::class, PriceMode::PerUnit);
        $pluCode = Fields::optionalText($fields, 'plu_code', 10);
        $shelfLifeDays = Fields::has($fields, 'shelf_life_days') ? self::positiveIntOrNull($fields, 'shelf_life_days') : null;
        $isSellable = Fields::flag($fields, 'is_sellable', true);
        $isPurchasable = Fields::flag($fields, 'is_purchasable', true);
        $isActive = Fields::flag($fields, 'is_active', true);
        $allowPriceOverride = Fields::flag($fields, 'allow_price_override', false);
        $allowDiscount = Fields::flag($fields, 'allow_discount', true);
        $weight = Money::amount($fields, 'weight', false, null);
        $dimensions = self::json($fields, 'dimensions');
        $variantOptions = self::json($fields, 'variant_options');
        $variantValues = self::json($fields, 'variant_values');
        $imagePath = Fields::optionalText($fields, 'image_path', 255);
        $sortOrder = Fields::integer($fields, 'sort_order', 0);

        $parentId = Identifier::foldNullable(Fields::has($fields, 'parent_id') ? $fields['parent_id'] : null, 'parent_id');

        ProductRules::checkParentage($type, $parentId);

        $connection = $this->tenant->get();

        return $connection->transaction(function () use (
            $connection, $command, $type, $parentId, $sku, $name, $shortName, $description, $categoryId, $brandId,
            $stockUnitId, $purchaseUnitId, $salesUnitId, $costPrice, $sellingPrice, $minSellingPrice, $msrp,
            $trackStock, $trackingMode, $isWeighable, $priceMode, $pluCode, $shelfLifeDays, $isSellable,
            $isPurchasable, $isActive, $allowPriceOverride, $allowDiscount, $weight, $dimensions, $variantOptions,
            $variantValues, $imagePath, $sortOrder,
        ): CreateProductResult {
            if ($connection->table('products')->whereRaw('lower(sku) = ?', [strtolower($sku)])->exists()) {
                throw CatalogRefused::duplicate("The sku [{$sku}] is already in use.");
            }

            if ($pluCode !== null && $connection->table('products')->whereRaw('lower(plu_code) = ?', [strtolower($pluCode)])->exists()) {
                throw CatalogRefused::duplicate("The plu_code [{$pluCode}] is already in use.");
            }

            Lookup::live($connection, 'categories', $categoryId, 'category');

            if ($brandId !== null) {
                Lookup::live($connection, 'brands', $brandId, 'brand');
            }

            $stockUnit = self::unit($connection, $stockUnitId, 'stock_unit_id');

            if ($purchaseUnitId !== null) {
                self::checkUnitDimension($connection, $purchaseUnitId, 'purchase_unit_id', (string) $stockUnit->dimension);
            }

            if ($salesUnitId !== null) {
                self::checkUnitDimension($connection, $salesUnitId, 'sales_unit_id', (string) $stockUnit->dimension);
            }

            if ($parentId !== null) {
                // W-1: locked, because two concurrent creates naming the same
                // variant_parent must not both read it before either commits.
                $parent = $connection->table('products')->where('id', $parentId)->whereNull('deleted_at')->lockForUpdate()->first();

                ProductRules::checkParent($parentId, $parent !== null, $parent === null ? null : ProductType::from($parent->type));
            }

            $productId = (string) Str::ulid();
            $now = $this->clock->timestamp();

            try {
                $connection->table('products')->insert([
                    'id' => $productId,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'deleted_at' => null,
                    'created_by' => $command->actorId,
                    'updated_by' => $command->actorId,
                    'type' => $type->value,
                    'parent_id' => $parentId,
                    'sku' => $sku,
                    'name' => $name,
                    'short_name' => $shortName,
                    'description' => $description,
                    'category_id' => $categoryId,
                    'brand_id' => $brandId,
                    'stock_unit_id' => $stockUnitId,
                    'purchase_unit_id' => $purchaseUnitId,
                    'sales_unit_id' => $salesUnitId,
                    'cost_price' => $costPrice,
                    'selling_price' => $sellingPrice,
                    'min_selling_price' => $minSellingPrice,
                    'msrp' => $msrp,
                    'track_stock' => $trackStock,
                    'tracking_mode' => $trackingMode->value,
                    'is_weighable' => $isWeighable,
                    'price_mode' => $priceMode->value,
                    'plu_code' => $pluCode,
                    'shelf_life_days' => $shelfLifeDays,
                    'is_sellable' => $isSellable,
                    'is_purchasable' => $isPurchasable,
                    'is_active' => $isActive,
                    'allow_price_override' => $allowPriceOverride,
                    'allow_discount' => $allowDiscount,
                    'weight' => $weight,
                    'dimensions' => $dimensions,
                    'variant_options' => $variantOptions,
                    'variant_values' => $variantValues,
                    'image_path' => $imagePath,
                    'sort_order' => $sortOrder,
                    'version' => 1,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw CatalogRefused::duplicate("The sku [{$sku}] or plu_code [".($pluCode ?? '').'] is already in use.');
            }

            $row = $connection->table('products')->where('id', $productId)->first();

            $this->audit->record(
                CatalogAuditActions::MODULE,
                $command->actorId,
                self::ACTION,
                'product',
                $productId,
                null,
                $row === null ? null : (array) $row,
            );

            return new CreateProductResult($productId);
        });
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private static function type(array $fields): ProductType
    {
        $value = $fields['type'] ?? null;

        if (! is_string($value) || ProductType::tryFrom($value) === null) {
            throw CatalogRefused::validation('The type must be one of: '.implode(', ', array_map(fn (ProductType $case): string => $case->value, ProductType::cases())).'.');
        }

        return ProductType::from($value);
    }

    /**
     * @template T of \BackedEnum
     *
     * @param  array<string, mixed>  $fields
     * @param  class-string<T>  $enumClass
     * @return T
     */
    private static function enumField(array $fields, string $key, string $enumClass, \BackedEnum $default): \BackedEnum
    {
        if (! Fields::has($fields, $key)) {
            return $default;
        }

        $value = $fields[$key];

        if (! is_string($value) || $enumClass::tryFrom($value) === null) {
            throw CatalogRefused::validation("The {$key} must be one of: ".implode(', ', array_map(static fn (\BackedEnum $case): string => (string) $case->value, $enumClass::cases())).'.');
        }

        return $enumClass::from($value);
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private static function positiveIntOrNull(array $fields, string $key): ?int
    {
        $value = $fields[$key];

        if ($value === null) {
            return null;
        }

        if (! is_int($value) || $value < 0) {
            throw CatalogRefused::validation("The {$key} must be a non-negative integer or null.");
        }

        return $value;
    }

    /**
     * Any JSON-encodable value, stored as given (AC-21).
     *
     * @param  array<string, mixed>  $fields
     */
    private static function json(array $fields, string $key): ?string
    {
        if (! Fields::has($fields, $key) || $fields[$key] === null) {
            return null;
        }

        $encoded = json_encode($fields[$key]);

        if ($encoded === false) {
            throw CatalogRefused::validation("The {$key} is not a valid JSON value.");
        }

        return $encoded;
    }

    private static function unit(Connection $connection, string $unitId, string $what): object
    {
        $row = $connection->table('units')->where('id', $unitId)->first();

        if ($row === null) {
            throw CatalogRefused::notFound("No unit has id [{$unitId}] ({$what}).");
        }

        return $row;
    }

    private static function checkUnitDimension(Connection $connection, string $unitId, string $what, string $stockDimension): void
    {
        $unit = self::unit($connection, $unitId, $what);

        if ((string) $unit->dimension !== $stockDimension) {
            throw CatalogRefused::dimensionMismatch("The {$what} [{$unitId}] ({$unit->dimension}) does not share the stock unit's dimension [{$stockDimension}].");
        }
    }
}
