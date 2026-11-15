<?php

/*
 * Customer addresses (docs/database.md section 6.9, T-028 AC-16 to AC-18).
 *
 * **No `[SOFT]` tag** (D-16): section 6.9 gives this table `[ID] [TS]`
 * only, so removing a row is a real `DELETE`, not a soft delete.
 *
 * **`default_customer_id` is the one-default-per-`(customer_id, type)`
 * backstop** (AC-17), the same `product_barcodes.primary_product_id`
 * shape: `CASE WHEN is_default THEN customer_id END`, stored, with a
 * `unique(default_customer_id, type)` key. A customer may have one
 * default `billing` address and one default `delivery` address at once,
 * but never two defaults of the same type.
 *
 * `customer_id`'s foreign key is one of this task's two `CASCADE`s
 * (docs/database.md section 6.9): an address is meaningless once its
 * customer is gone for good.
 *
 * @phase expand
 */

use Flexipos\Kernel\Schema\Support\IdentifierColumn;
use Flexipos\Kernel\Schema\Support\JsonValidity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_addresses', function (Blueprint $table) {
            IdentifierColumn::primary($table);
            $table->dateTime('created_at', 3);
            $table->dateTime('updated_at', 3);
            IdentifierColumn::required($table, 'customer_id');
            $table->string('type', 30);
            $table->string('label', 100)->nullable();
            $table->json('address')->nullable();
            $table->boolean('is_default')->default(false);
            IdentifierColumn::nullable($table, 'default_customer_id')
                ->storedAs('CASE WHEN is_default THEN customer_id END');
            $table->json('geo')->nullable();

            $table->unique(['default_customer_id', 'type']);

            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
        });

        JsonValidity::enforce('customer_addresses', 'address', 'geo');
    }
};
