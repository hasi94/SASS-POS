<?php

/*
 * Products, including variants and the stock/purchase/sales unit split
 * (docs/database.md section 6.3, T-027 AC-18 to AC-27, AC-27a).
 *
 * A variant is a `products` row with `type = variant` and `parent_id`
 * pointing at a `variant_parent` row - there is no second table (D-7). `type`
 * is the application enum `simple|variant_parent|variant|service|bundle|
 * composite`, `VARCHAR(30)`. `stock_unit_id` is the base unit and is
 * immutable from the row's first save onward (AC-22, D-6); `purchase_unit_id`
 * and `sales_unit_id` are UI defaults only, checked for `units.dimension`
 * compatibility at save time, never full resolvability (AC-23, D-9).
 *
 * `plu_code` is unique across every product, including soft-deleted ones
 * (AC-24). `is_weighable`, `price_mode` and `plu_code` carry no cross-column
 * coupling (AC-25, D-8); `track_stock` and `tracking_mode` are independent
 * the same way (AC-26). Defaults are Ruling 8's: `track_stock` true,
 * `tracking_mode` quantity, `is_weighable` false, `price_mode` per_unit,
 * `is_sellable`/`is_purchasable`/`is_active` true, `allow_price_override`
 * false, `allow_discount` true, money columns 0.0000, `min_selling_price`/
 * `msrp` null. `version` starts at 1 and every update increments it.
 *
 * **Six deferred columns are not built here** (AC-27, D-3): `tax_group_id`,
 * `purchase_tax_group_id`, `income_account_id`, `cogs_account_id`,
 * `inventory_account_id`, `kitchen_station_id` - `tax_groups` (FP-231),
 * `accounts` (FP-260) and a kitchen-station table (FP-239) do not exist yet.
 * **`custom_fields` is not built either** (AC-27a, D-12): `custom_field_
 * definitions` does not exist, following T-014's `branches` precedent.
 *
 * The `FULLTEXT` index over `name`/`short_name`/`sku` is MySQL/MariaDB only
 * (`FullTextIndex`'s own docblock: SQLite's grammar throws on it outright),
 * and the three JSON columns carry `JsonValidity`'s engine-only check.
 *
 * @phase expand
 */

use Flexipos\Kernel\Schema\Support\FullTextIndex;
use Flexipos\Kernel\Schema\Support\IdentifierColumn;
use Flexipos\Kernel\Schema\Support\JsonValidity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            IdentifierColumn::primary($table);
            $table->dateTime('created_at', 3);
            $table->dateTime('updated_at', 3);
            $table->dateTime('deleted_at', 3)->nullable();
            IdentifierColumn::nullable($table, 'created_by');
            IdentifierColumn::nullable($table, 'updated_by');
            $table->string('type', 30);
            IdentifierColumn::nullable($table, 'parent_id');
            $table->string('sku', 50);
            $table->string('name', 150);
            $table->string('short_name', 40)->nullable();
            $table->text('description')->nullable();
            IdentifierColumn::required($table, 'category_id');
            IdentifierColumn::nullable($table, 'brand_id');
            IdentifierColumn::required($table, 'stock_unit_id');
            IdentifierColumn::nullable($table, 'purchase_unit_id');
            IdentifierColumn::nullable($table, 'sales_unit_id');
            $table->decimal('cost_price', 18, 4)->default(0);
            $table->decimal('selling_price', 18, 4)->default(0);
            $table->decimal('min_selling_price', 18, 4)->nullable();
            $table->decimal('msrp', 18, 4)->nullable();
            $table->boolean('track_stock')->default(true);
            $table->string('tracking_mode', 30)->default('quantity');
            $table->boolean('is_weighable')->default(false);
            $table->string('price_mode', 30)->default('per_unit');
            $table->string('plu_code', 10)->nullable();
            $table->unsignedInteger('shelf_life_days')->nullable();
            $table->boolean('is_sellable')->default(true);
            $table->boolean('is_purchasable')->default(true);
            $table->boolean('is_active')->default(true);
            $table->boolean('allow_price_override')->default(false);
            $table->boolean('allow_discount')->default(true);
            $table->decimal('weight', 18, 4)->nullable();
            $table->json('dimensions')->nullable();
            $table->json('variant_options')->nullable();
            $table->json('variant_values')->nullable();
            $table->string('image_path', 255)->nullable();
            $table->integer('sort_order')->default(0);
            $table->unsignedBigInteger('version')->default(1);

            $table->unique('sku');
            $table->unique('plu_code');
            $table->index('category_id');
            $table->index('brand_id');
            $table->index(['is_active', 'is_sellable']);
            $table->index('parent_id');

            $table->foreign('parent_id')->references('id')->on('products');
            $table->foreign('category_id')->references('id')->on('categories');
            $table->foreign('brand_id')->references('id')->on('brands');
            $table->foreign('stock_unit_id')->references('id')->on('units');
            $table->foreign('purchase_unit_id')->references('id')->on('units');
            $table->foreign('sales_unit_id')->references('id')->on('units');
            $table->foreign('created_by')->references('id')->on('employees');
            $table->foreign('updated_by')->references('id')->on('employees');
        });

        JsonValidity::enforce('products', 'dimensions', 'variant_options', 'variant_values');
        FullTextIndex::add('products', ['name', 'short_name', 'sku']);
    }
};
