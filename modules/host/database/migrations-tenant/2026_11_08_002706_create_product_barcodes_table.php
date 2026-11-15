<?php

/*
 * Exact-match product barcodes (docs/database.md section 6.3, T-027 AC-28
 * to AC-31). `barcode_rules` and its priority-ordered fallback parser are
 * out of scope (FP-423, AC-31).
 *
 * `unit_id` is optional - null means "this barcode scans as the product's
 * own stock unit" - and when supplied must share the owning product's
 * `stock_unit_id` dimension (AC-29, F-3/D-14): checked by `AddProductBarcode`
 * /`UpdateProductBarcode`, never by this table.
 *
 * **`primary_product_id` is the one-default-per-product backstop, and it is
 * deliberately NOT section 3.3's sentinel** (the `employee_branches.
 * default_employee_id` shape, AC-30): `CASE WHEN is_primary THEN product_id
 * END`, stored, with a plain unique index. It holds the product id on the
 * primary row and NULL on every other, and NULLs being distinct in a unique
 * index is exactly what leaves the non-primary rows unconstrained - a
 * unique key on `(product_id, is_primary)` would also refuse a second
 * *non*-primary row, which is the normal case.
 *
 * `product_id`'s foreign key is the one of this task's two `CASCADE`s
 * (`docs/database.md` section 6.3): a product's barcodes are meaningless
 * once the product row itself is gone for good. No `[SOFT]`, no TS: the
 * barcode value is immutable, and changing it is remove plus re-add.
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
        Schema::create('product_barcodes', function (Blueprint $table) {
            IdentifierColumn::primary($table);
            IdentifierColumn::required($table, 'product_id');
            $table->string('barcode', 64);
            IdentifierColumn::nullable($table, 'unit_id');
            $table->decimal('quantity', 18, 4)->default(1);
            $table->boolean('is_primary')->default(false);
            IdentifierColumn::nullable($table, 'primary_product_id')
                ->storedAs('CASE WHEN is_primary THEN product_id END');

            $table->unique('barcode');
            $table->unique('primary_product_id');

            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->foreign('unit_id')->references('id')->on('units');
        });
    }
};
