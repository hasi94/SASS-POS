<?php

/*
 * Per-location stock settings, in the product's own stock unit and never
 * converted (docs/database.md section 6.3, T-027 AC-32).
 *
 * A composite primary key is the uniqueness: a duplicate `(product_id,
 * location_id)` pair is refused as already existing, never a second row
 * (`CreateProductLocation`).
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
        Schema::create('product_locations', function (Blueprint $table) {
            IdentifierColumn::required($table, 'product_id');
            IdentifierColumn::required($table, 'location_id');
            $table->boolean('is_available')->default(true);
            $table->decimal('reorder_level', 18, 4)->default(0);
            $table->decimal('reorder_qty', 18, 4)->default(0);
            $table->decimal('max_level', 18, 4)->default(0);
            $table->string('bin_location', 40)->nullable();
            $table->dateTime('created_at', 3);
            $table->dateTime('updated_at', 3);

            $table->primary(['product_id', 'location_id']);

            $table->foreign('product_id')->references('id')->on('products');
            $table->foreign('location_id')->references('id')->on('locations');
        });
    }
};
