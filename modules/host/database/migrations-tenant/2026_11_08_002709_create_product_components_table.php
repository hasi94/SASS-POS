<?php

/*
 * Bundles, kits, composites and recipes: one product's component line of
 * another (docs/database.md section 6.3, T-027 AC-34, AC-34a).
 *
 * `unit_id`, `quantity`, `unit_factor` and `base_quantity` are the full
 * `[UOM]` quad: resolved through `UnitResolver::snapshot()` and
 * **snapshotted at write time** (D-62) - a later change to the unit's
 * conversion factor never silently re-scales an already-written recipe row.
 * Changing the recipe is remove plus re-add, which re-resolves (Ruling 8).
 *
 * The unique key on `(parent_product_id, component_product_id)` is AC-34's
 * duplicate-pair refusal; the transitive-cycle refusal (AC-34a) is
 * `ComponentGraph`'s job at write time, not a constraint this table can
 * express. No `[SOFT]`, no TS (docs/database.md section 6.3).
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
        Schema::create('product_components', function (Blueprint $table) {
            IdentifierColumn::primary($table);
            IdentifierColumn::required($table, 'parent_product_id');
            IdentifierColumn::required($table, 'component_product_id');
            IdentifierColumn::required($table, 'unit_id');
            $table->decimal('quantity', 18, 4);
            $table->decimal('unit_factor', 18, 6);
            $table->decimal('base_quantity', 18, 4);
            $table->decimal('wastage_percent', 9, 4)->default(0);
            $table->boolean('is_optional')->default(false);
            $table->integer('sort_order')->default(0);

            $table->unique(['parent_product_id', 'component_product_id']);

            $table->foreign('parent_product_id')->references('id')->on('products');
            $table->foreign('component_product_id')->references('id')->on('products');
            $table->foreign('unit_id')->references('id')->on('units');
        });
    }
};
