<?php

/*
 * Named unit-to-unit conversion rows: `UnitResolver`'s ladder steps 1 and 2
 * (docs/database.md section 6.3, T-027 AC-13, AC-13a, AC-14).
 *
 * `product_id` null means a global rule; non-null scopes the rule to one
 * product. The ladder reads a row only as `from_unit_id = the line unit,
 * to_unit_id = the product's own stock unit` - never inverted, never
 * chained (F-7) - so `CreateUnitConversion` refuses a product-scoped row
 * whose `to_unit_id` is not that product's `stock_unit_id` (AC-13a); a
 * global row's `to_unit_id` carries no such constraint.
 *
 * `product_key` is the `IdentifierColumn::sentinel()` shape (`docs/
 * database.md` section 3.3, D-6 there): `COALESCE(product_id, '-')`, because
 * MySQL/MariaDB treat NULLs as distinct in a unique index and null here is a
 * meaningful value ("every product"). The unique key on `(product_key,
 * from_unit_id, to_unit_id)` is exactly one global rule and exactly one
 * per-product rule for any ordered unit pair (AC-13's negative case).
 *
 * No `[SOFT]`, no TS (`docs/database.md` section 6.3): a factor change is
 * remove plus create, so the row's own history has nothing to preserve.
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
        Schema::create('unit_conversions', function (Blueprint $table) {
            IdentifierColumn::primary($table);
            IdentifierColumn::nullable($table, 'product_id');
            IdentifierColumn::required($table, 'from_unit_id');
            IdentifierColumn::required($table, 'to_unit_id');
            $table->decimal('factor', 18, 6);
            IdentifierColumn::sentinel($table, 'product_id', 'product_key');

            $table->unique(['product_key', 'from_unit_id', 'to_unit_id']);

            $table->foreign('product_id')->references('id')->on('products');
            $table->foreign('from_unit_id')->references('id')->on('units');
            $table->foreign('to_unit_id')->references('id')->on('units');
        });
    }
};
