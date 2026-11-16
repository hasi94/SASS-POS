<?php

/*
 * Purchase request items (docs/database.md section 6.10, T-034 AC-19 to AC-25).
 *
 * The `[UOM]` quad comes only from `UnitResolver`; `estimated_cost` is a plain
 * `(18,4)` hint, nullable, with no tax or discount, so a request carries no
 * pricing. `supplier_id` is the buyer's preferred supplier for the line.
 *
 * `purchase_order_item_id` is set when the request is converted, and keys the
 * order line the request line became. It is why this file sorts **after**
 * `create_purchase_order_items_table`: a table that keys onto another must be
 * created once its target exists.
 *
 * `request_id` cascades.
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
        Schema::create('purchase_request_items', function (Blueprint $table) {
            IdentifierColumn::primary($table);
            IdentifierColumn::required($table, 'request_id');
            $table->unsignedSmallInteger('line_no');
            IdentifierColumn::required($table, 'product_id');
            IdentifierColumn::required($table, 'unit_id');
            $table->decimal('quantity', 18, 4);
            $table->decimal('unit_factor', 18, 6);
            $table->decimal('base_quantity', 18, 4);
            $table->decimal('estimated_cost', 18, 4)->nullable();
            IdentifierColumn::nullable($table, 'supplier_id');
            IdentifierColumn::nullable($table, 'purchase_order_item_id');

            $table->unique(['request_id', 'line_no']);
            $table->index('product_id');
            $table->index('supplier_id');

            $table->foreign('request_id')->references('id')->on('purchase_requests')->cascadeOnDelete();
            $table->foreign('product_id')->references('id')->on('products');
            $table->foreign('unit_id')->references('id')->on('units');
            $table->foreign('supplier_id')->references('id')->on('suppliers');
            $table->foreign('purchase_order_item_id')->references('id')->on('purchase_order_items');
        });
    }
};
