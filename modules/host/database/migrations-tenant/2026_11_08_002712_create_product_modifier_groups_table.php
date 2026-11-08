<?php

/*
 * The attach join between a product and a modifier group (docs/database.md
 * section 6.3, T-027 AC-39). A duplicate `(product_id, group_id)` pair is
 * refused as already existing, never a second row (`AttachModifierGroup`).
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
        Schema::create('product_modifier_groups', function (Blueprint $table) {
            IdentifierColumn::required($table, 'product_id');
            IdentifierColumn::required($table, 'group_id');
            $table->integer('sort_order')->default(0);

            $table->primary(['product_id', 'group_id']);

            $table->foreign('product_id')->references('id')->on('products');
            $table->foreign('group_id')->references('id')->on('modifier_groups');
        });
    }
};
