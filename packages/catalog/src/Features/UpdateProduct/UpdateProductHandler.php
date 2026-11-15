<?php

declare(strict_types=1);

namespace Flexipos\Catalog\Features\UpdateProduct;

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

/**
 * Update a product (T-027 AC-18 to AC-27, AC-27a, plan Ruling 8).
 *
 * **`stock_unit_id` is immutable** (AC-22). An update that names a value
 * different from the one already stored is refused **whole**, before any
 * other field in the same request is applied, so the row (and every other
 * field sent alongside it) is byte-identical afterwards. The same value is
 * a no-op and does not block the rest of the update.
 */
final class UpdateProductHandler
{
    public const ACTION = 'catalog_product_updated';

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

    public function handle(UpdateProduct $command): void
    {
        $fields = $command->fields;

        Fields::only($fields, self::FIELDS);

        if ($fields === []) {
            throw CatalogRefused::validation('An update must name at least one field.');
        }

        $productId = Identifier::fold($command->productId, 'productId');

        $connection = $this->tenant->get();

        $connection->transaction(function () use ($connection, $command, $productId, $fields): void {
            $before = $connection->table('products')->where('id', $productId)->lockForUpdate()->first();

            if ($before === null) {
                throw CatalogRefused::notFound("The product [{$productId}] does not exist.");
            }

            // AC-22: a different stock_unit_id refuses the whole request,
            // before anything else is validated or applied.
            if (Fields::has($fields, 'stock_unit_id')) {
                $newStockUnitId = Identifier::fold($fields['stock_unit_id'], 'stock_unit_id');

                if ($newStockUnitId !== $before->stock_unit_id) {
                    throw CatalogRefused::immutable("Product [{$productId}]'s stock_unit_id cannot be changed.");
                }
            }

            $changes = $this->resolveChanges($connection, $productId, $before, $fields);

            if (isset($changes['sku']) && $changes['sku'] !== $before->sku) {
                $duplicate = $connection->table('products')
                    ->whereRaw('lower(sku) = ?', [strtolower($changes['sku'])])
                    ->where('id', '!=', $productId)
                    ->exists();

                if ($duplicate) {
                    throw CatalogRefused::duplicate("The sku [{$changes['sku']}] is already in use.");
                }
            }

            if (array_key_exists('plu_code', $changes) && $changes['plu_code'] !== $before->plu_code) {
                if ($changes['plu_code'] !== null) {
                    $duplicate = $connection->table('products')
                        ->whereRaw('lower(plu_code) = ?', [strtolower($changes['plu_code'])])
                        ->where('id', '!=', $productId)
                        ->exists();

                    if ($duplicate) {
                        throw CatalogRefused::duplicate("The plu_code [{$changes['plu_code']}] is already in use.");
                    }
                }
            }

            $changes['version'] = (int) $before->version + 1;
            $changes['updated_at'] = $this->clock->timestamp();
            $changes['updated_by'] = $command->actorId;

            try {
                $connection->table('products')->where('id', $productId)->update($changes);
            } catch (UniqueConstraintViolationException) {
                throw CatalogRefused::duplicate('The sku or plu_code is already in use.');
            }

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

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private function resolveChanges(Connection $connection, string $productId, object $before, array $fields): array
    {
        $changes = [];

        $currentType = ProductType::from((string) $before->type);
        $newType = $currentType;

        if (Fields::has($fields, 'type')) {
            $newType = self::type($fields);
            $changes['type'] = $newType->value;
        }

        $currentParentId = $before->parent_id === null ? null : (string) $before->parent_id;
        $newParentId = $currentParentId;
        $parentTouched = Fields::has($fields, 'parent_id') || Fields::has($fields, 'type');

        if (Fields::has($fields, 'parent_id')) {
            $newParentId = Identifier::foldNullable($fields['parent_id'], 'parent_id');

            if ($newParentId === $productId) {
                throw CatalogRefused::validation("Product [{$productId}] cannot be its own parent.");
            }

            $changes['parent_id'] = $newParentId;
        }

        if ($parentTouched) {
            ProductRules::checkParentage($newType, $newParentId);

            if ($newParentId !== null) {
                $parent = $connection->table('products')->where('id', $newParentId)->whereNull('deleted_at')->first();

                ProductRules::checkParent($newParentId, $parent !== null, $parent === null ? null : ProductType::from($parent->type));
            }
        }

        if (Fields::has($fields, 'type') && $newType !== $currentType) {
            $hasLiveChildren = $connection->table('products')->where('parent_id', $productId)->whereNull('deleted_at')->exists();
            ProductRules::checkTypeChange($productId, $currentType, $newType, $hasLiveChildren);
        }

        if (Fields::has($fields, 'sku')) {
            $changes['sku'] = Fields::requiredText($fields, 'sku', 50);
        }

        if (Fields::has($fields, 'name')) {
            $changes['name'] = Fields::requiredText($fields, 'name', 150);
        }

        if (Fields::has($fields, 'short_name')) {
            $changes['short_name'] = Fields::optionalText($fields, 'short_name', 40);
        }

        if (Fields::has($fields, 'description')) {
            $changes['description'] = Fields::optionalText($fields, 'description', 65535);
        }

        if (Fields::has($fields, 'category_id')) {
            $categoryId = Identifier::fold($fields['category_id'], 'category_id');
            Lookup::live($connection, 'categories', $categoryId, 'category');
            $changes['category_id'] = $categoryId;
        }

        if (Fields::has($fields, 'brand_id')) {
            $brandId = Identifier::foldNullable($fields['brand_id'], 'brand_id');

            if ($brandId !== null) {
                Lookup::live($connection, 'brands', $brandId, 'brand');
            }

            $changes['brand_id'] = $brandId;
        }

        $stockDimension = (string) self::unit($connection, (string) $before->stock_unit_id, 'stock_unit_id')->dimension;

        if (Fields::has($fields, 'purchase_unit_id')) {
            $purchaseUnitId = Identifier::foldNullable($fields['purchase_unit_id'], 'purchase_unit_id');

            if ($purchaseUnitId !== null) {
                self::checkUnitDimension($connection, $purchaseUnitId, 'purchase_unit_id', $stockDimension);
            }

            $changes['purchase_unit_id'] = $purchaseUnitId;
        }

        if (Fields::has($fields, 'sales_unit_id')) {
            $salesUnitId = Identifier::foldNullable($fields['sales_unit_id'], 'sales_unit_id');

            if ($salesUnitId !== null) {
                self::checkUnitDimension($connection, $salesUnitId, 'sales_unit_id', $stockDimension);
            }

            $changes['sales_unit_id'] = $salesUnitId;
        }

        if (Fields::has($fields, 'cost_price')) {
            $changes['cost_price'] = Money::amount($fields, 'cost_price', false, '0.0000');
        }

        if (Fields::has($fields, 'selling_price')) {
            $changes['selling_price'] = Money::amount($fields, 'selling_price', false, '0.0000');
        }

        if (Fields::has($fields, 'min_selling_price')) {
            $changes['min_selling_price'] = Money::amount($fields, 'min_selling_price', false, null);
        }

        if (Fields::has($fields, 'msrp')) {
            $changes['msrp'] = Money::amount($fields, 'msrp', false, null);
        }

        if (Fields::has($fields, 'track_stock')) {
            $changes['track_stock'] = Fields::flag($fields, 'track_stock', true);
        }

        if (Fields::has($fields, 'tracking_mode')) {
            $changes['tracking_mode'] = self::enumValue($fields, 'tracking_mode', TrackingMode::class);
        }

        if (Fields::has($fields, 'is_weighable')) {
            $changes['is_weighable'] = Fields::flag($fields, 'is_weighable', false);
        }

        if (Fields::has($fields, 'price_mode')) {
            $changes['price_mode'] = self::enumValue($fields, 'price_mode', PriceMode::class);
        }

        if (Fields::has($fields, 'plu_code')) {
            $changes['plu_code'] = Fields::optionalText($fields, 'plu_code', 10);
        }

        if (Fields::has($fields, 'shelf_life_days')) {
            $value = $fields['shelf_life_days'];

            if ($value !== null && (! is_int($value) || $value < 0)) {
                throw CatalogRefused::validation('The shelf_life_days must be a non-negative integer or null.');
            }

            $changes['shelf_life_days'] = $value;
        }

        if (Fields::has($fields, 'is_sellable')) {
            $changes['is_sellable'] = Fields::flag($fields, 'is_sellable', true);
        }

        if (Fields::has($fields, 'is_purchasable')) {
            $changes['is_purchasable'] = Fields::flag($fields, 'is_purchasable', true);
        }

        if (Fields::has($fields, 'is_active')) {
            $changes['is_active'] = Fields::flag($fields, 'is_active', true);
        }

        if (Fields::has($fields, 'allow_price_override')) {
            $changes['allow_price_override'] = Fields::flag($fields, 'allow_price_override', false);
        }

        if (Fields::has($fields, 'allow_discount')) {
            $changes['allow_discount'] = Fields::flag($fields, 'allow_discount', true);
        }

        if (Fields::has($fields, 'weight')) {
            $changes['weight'] = Money::amount($fields, 'weight', false, null);
        }

        foreach (['dimensions', 'variant_options', 'variant_values'] as $jsonField) {
            if (Fields::has($fields, $jsonField)) {
                $changes[$jsonField] = self::json($fields, $jsonField);
            }
        }

        if (Fields::has($fields, 'image_path')) {
            $changes['image_path'] = Fields::optionalText($fields, 'image_path', 255);
        }

        if (Fields::has($fields, 'sort_order')) {
            $changes['sort_order'] = Fields::integer($fields, 'sort_order', 0);
        }

        return $changes;
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
     */
    private static function enumValue(array $fields, string $key, string $enumClass): string
    {
        $value = $fields[$key];

        if (! is_string($value) || $enumClass::tryFrom($value) === null) {
            throw CatalogRefused::validation("The {$key} must be one of: ".implode(', ', array_map(static fn (\BackedEnum $case): string => (string) $case->value, $enumClass::cases())).'.');
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private static function json(array $fields, string $key): ?string
    {
        if ($fields[$key] === null) {
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
