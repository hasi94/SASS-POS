<?php

/*
 * Per-branch selling-price overrides (docs/database.md section 6.3, T-027
 * AC-33). `selling_price` null inherits `products.selling_price`; this task
 * stores the override only, it does not implement the pricing ladder that
 * reads it (FP-231). `Q-12` is answered no (D-5): no `sales_unit_id` here.
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
        Schema::create('product_branches', function (Blueprint $table) {
            IdentifierColumn::required($table, 'product_id');
            IdentifierColumn::required($table, 'branch_id');
            $table->boolean('is_available')->default(true);
            $table->decimal('selling_price', 18, 4)->nullable();
            $table->dateTime('created_at', 3);
            $table->dateTime('updated_at', 3);

            $table->primary(['product_id', 'branch_id']);

            $table->foreign('product_id')->references('id')->on('products');
            $table->foreign('branch_id')->references('id')->on('branches');
        });
    }
};
