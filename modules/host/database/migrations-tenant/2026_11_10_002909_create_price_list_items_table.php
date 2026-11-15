<?php

/*
 * Price list items (docs/database.md section 6.4, T-029 AC-13, AC-15 to
 * AC-17).
 *
 * **`unit_id` is the unit `price` is per, and null means "priced per stock
 * unit, derive by factor"** (the `[UOM]` tag reduced to `unit_id` on master
 * data, section 3.1). `min_qty` is a volume break on the product's **base**
 * quantity, never on the line's own unit (AC-16), and defaults to 1.
 *
 * **`unit_key` is the sentinel the unique key is built on**: stored
 * `COALESCE(unit_id, '-')`, `IdentifierColumn::sentinel()`'s shape (section
 * 3.3). MySQL and MariaDB treat NULLs as distinct in a unique index, and null
 * is a meaningful value here, so a key over `unit_id` itself would admit any
 * number of duplicate "per stock unit" rows at the same `min_qty`; on this
 * table the sentinel is not optional. The key is exactly one row per
 * `(list, product, unit or none, min_qty)`.
 *
 * `discount_percent` is the item's own reduction, applied by the ladder when
 * this row wins (AC-16a, D-17). No timestamps and no soft delete: removing an
 * item is hard, and the list's soft delete is what retires a whole sheet.
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
        Schema::create('price_list_items', function (Blueprint $table) {
            IdentifierColumn::primary($table);
            IdentifierColumn::required($table, 'price_list_id');
            IdentifierColumn::required($table, 'product_id');
            IdentifierColumn::nullable($table, 'unit_id');
            $table->decimal('min_qty', 18, 4)->default(1);
            $table->decimal('price', 18, 4);
            $table->decimal('discount_percent', 9, 4)->nullable();
            IdentifierColumn::sentinel($table, 'unit_id', 'unit_key');

            $table->unique(['price_list_id', 'product_id', 'unit_key', 'min_qty']);
            $table->index('product_id');

            $table->foreign('price_list_id')->references('id')->on('price_lists')->cascadeOnDelete();
            $table->foreign('product_id')->references('id')->on('products');
            $table->foreign('unit_id')->references('id')->on('units');
        });
    }
};
