<?php

/*
 * Purchase order items (docs/database.md section 6.10, T-034 AC-42 to AC-49).
 *
 * The `[UOM]` quad (`unit_id`, `quantity`, `unit_factor`, `base_quantity`)
 * comes only from `UnitResolver`, and every discount, tax and total figure
 * comes only from `PurchaseLinePricing`. `unit_factor` is the one `(18,6)`
 * column and `discount_percent` the one `(9,4)`; every money and quantity
 * column is `(18,4)`.
 *
 * `received_quantity` is in the line's own unit, for display on a document the
 * supplier reads; **`received_base_quantity` is the authority**, and the
 * `partially_received` to `received` transition compares base to base
 * (docs/database.md section 6.10, AC-70 to AC-72).
 *
 * **`unit_code` and `tax_breakdown` are not built** (D-3, D-27): a purchase
 * order is not reprinted from a frozen tax breakdown, and the unit is read
 * back from `units`.
 *
 * `order_id` cascades.
 *
 * @phase expand
 */

use Flexipos\Kernel\Schema\Support\IdentifierColumn;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_order_items', function (Blueprint $table) {
            IdentifierColumn::primary($table);
            IdentifierColumn::required($table, 'order_id');
            $table->unsignedSmallInteger('line_no');
            IdentifierColumn::required($table, 'product_id');
            $table->string('description', 255);
            IdentifierColumn::required($table, 'unit_id');
            $table->decimal('quantity', 18, 4);
            $table->decimal('unit_factor', 18, 6);
            $table->decimal('base_quantity', 18, 4);
            $table->decimal('unit_cost', 18, 4);
            $table->decimal('subtotal', 18, 4);
            $table->decimal('discount_percent', 9, 4)->default(0);
            $table->decimal('discount_amount', 18, 4);
            IdentifierColumn::required($table, 'tax_group_id');
            $table->decimal('tax_amount', 18, 4);
            $table->decimal('line_total', 18, 4);
            $table->decimal('received_quantity', 18, 4)->default(0);
            $table->decimal('received_base_quantity', 18, 4)->default(0);
            $table->date('expected_date')->nullable();

            $table->unique(['order_id', 'line_no']);
            $table->index('product_id');

            $table->foreign('order_id')->references('id')->on('purchase_orders')->cascadeOnDelete();
            $table->foreign('product_id')->references('id')->on('products');
            $table->foreign('unit_id')->references('id')->on('units');
            $table->foreign('tax_group_id')->references('id')->on('tax_groups');
        });
    }
};
