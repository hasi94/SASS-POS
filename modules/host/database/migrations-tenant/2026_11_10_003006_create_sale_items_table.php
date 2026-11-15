<?php

/*
 * Sale items (docs/database.md section 6.7, T-030 AC-2 to AC-10).
 *
 * The `[UOM]` quad (`unit_id`, `quantity`, `unit_factor`, `base_quantity`)
 * comes only from `UnitResolver`, and every price, discount and tax figure
 * comes only from `PricingEngine`. `unit_factor` is the one `(18,6)` column and
 * `discount_percent` the one `(9,4)`; every money and quantity column is
 * `(18,4)`.
 *
 * `product_name`, `sku`, `unit_code`, `category_id` and `brand_id` are
 * **snapshots**: a receipt reprints identically after the product is renamed
 * or recategorised, so `category_id` and `brand_id` carry no foreign key.
 *
 * **`subtotal` is this task's own addition** (D-44, F-18): the engine's
 * pre-discount subtotal, so the header rollup is a plain sum and never
 * branches on inclusivity.
 *
 * `unit_cost` and `line_cost` are the COGS placeholders FP-234 fills.
 * `refunded_quantity` is in base units (D-70).
 *
 * **Deferred columns are not built** (D-9, D-33, F-8): `batch_id`, `serial_id`
 * and `kitchen_station_id` belong to batches, serials and the kitchen, none of
 * which exist yet.
 *
 * `sale_id` cascades. `refund_of_item_id` is a self-key.
 *
 * @phase expand
 */

use Flexipos\Kernel\Schema\Support\IdentifierColumn;
use Flexipos\Kernel\Schema\Support\JsonValidity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_items', function (Blueprint $table) {
            IdentifierColumn::primary($table);
            IdentifierColumn::required($table, 'sale_id');
            $table->unsignedSmallInteger('line_no');
            IdentifierColumn::required($table, 'product_id');
            $table->string('product_name', 150);
            $table->string('sku', 50)->nullable();
            $table->string('unit_code', 50);
            IdentifierColumn::nullable($table, 'category_id');
            IdentifierColumn::nullable($table, 'brand_id');
            IdentifierColumn::required($table, 'unit_id');
            $table->decimal('quantity', 18, 4);
            $table->decimal('unit_factor', 18, 6);
            $table->decimal('base_quantity', 18, 4);
            $table->decimal('list_price', 18, 4);
            $table->decimal('unit_price', 18, 4);
            $table->decimal('subtotal', 18, 4);
            IdentifierColumn::nullable($table, 'discount_id');
            $table->decimal('discount_percent', 9, 4)->nullable();
            $table->decimal('discount_amount', 18, 4);
            IdentifierColumn::required($table, 'tax_group_id');
            $table->decimal('tax_amount', 18, 4);
            $table->json('tax_breakdown');
            $table->boolean('price_from_label')->default(false);
            $table->decimal('line_total', 18, 4);
            $table->decimal('unit_cost', 18, 4)->nullable();
            $table->decimal('line_cost', 18, 4)->nullable();
            IdentifierColumn::nullable($table, 'employee_id');
            $table->json('modifiers')->nullable();
            $table->text('notes')->nullable();
            $table->decimal('refunded_quantity', 18, 4)->default(0);
            IdentifierColumn::nullable($table, 'refund_of_item_id');
            IdentifierColumn::nullable($table, 'price_overridden_by');
            $table->integer('sort_order')->default(0);

            $table->unique(['sale_id', 'line_no']);
            $table->index('product_id');
            $table->index('employee_id');

            $table->foreign('sale_id')->references('id')->on('sales')->cascadeOnDelete();
            $table->foreign('product_id')->references('id')->on('products');
            $table->foreign('unit_id')->references('id')->on('units');
            $table->foreign('discount_id')->references('id')->on('discounts');
            $table->foreign('tax_group_id')->references('id')->on('tax_groups');
            $table->foreign('employee_id')->references('id')->on('employees');
            $table->foreign('refund_of_item_id')->references('id')->on('sale_items');
            $table->foreign('price_overridden_by')->references('id')->on('employees');
        });

        JsonValidity::enforce('sale_items', 'tax_breakdown', 'modifiers');
    }
};
