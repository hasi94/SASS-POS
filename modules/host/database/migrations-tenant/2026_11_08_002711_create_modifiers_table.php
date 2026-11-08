<?php

/*
 * Individual modifiers within a group (docs/database.md section 6.3 and
 * 6.5, T-027 AC-37, AC-38, AC-40, AC-40a).
 *
 * `linked_product_id`, `linked_quantity` and `linked_unit_id` are all three
 * or none (AC-37); when present, `linked_unit_id` must share the linked
 * product's own `stock_unit_id` dimension (AC-38, F-3/D-14) - checked by
 * `CreateModifier`, never by this table. **No `unit_factor` column here**
 * (AC-40a, D-19, §3.1/§14's own exemption): a depleting modifier resolves a
 * factor fresh from `linked_unit_id`/`linked_quantity` at the moment of
 * consumption, never stores one - unlike `product_components`, which
 * snapshots at write time. The posting rule that actually depletes stock is
 * "deliberately unspecified" (`docs/database.md` itself, AC-40) and belongs
 * to the restaurant workstream.
 *
 * `group_id`'s foreign key is this task's other `CASCADE`: a modifier has no
 * meaning once its own group is gone for good. No `[SOFT]`, no TS
 * (docs/database.md section 6.3).
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
        Schema::create('modifiers', function (Blueprint $table) {
            IdentifierColumn::primary($table);
            IdentifierColumn::required($table, 'group_id');
            $table->string('name', 150);
            $table->decimal('price_delta', 18, 4)->default(0);
            $table->decimal('cost_delta', 18, 4)->default(0);
            IdentifierColumn::nullable($table, 'linked_product_id');
            $table->decimal('linked_quantity', 18, 4)->nullable();
            IdentifierColumn::nullable($table, 'linked_unit_id');
            $table->string('kitchen_note', 255)->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);

            $table->foreign('group_id')->references('id')->on('modifier_groups')->cascadeOnDelete();
            $table->foreign('linked_product_id')->references('id')->on('products');
            $table->foreign('linked_unit_id')->references('id')->on('units');
        });
    }
};
