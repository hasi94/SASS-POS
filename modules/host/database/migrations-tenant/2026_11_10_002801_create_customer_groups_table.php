<?php

/*
 * Customer groups (docs/database.md section 6.9, T-028 AC-1 to AC-5).
 *
 * `discount_percent`, `default_credit_limit`, `loyalty_multiplier` are
 * plain defaults a `CreateCustomer` copies once at create time (AC-7,
 * F-10) - this task reads and writes them, and never applies them to a
 * sale or a price list (D-4, AC-5): FP-231 owns the pricing ladder.
 *
 * **`default_marker` is the one-default-group backstop, and it is
 * deliberately NOT section 3.3's sentinel** (the `product_barcodes.
 * primary_product_id` shape, T-027 AC-30): `CASE WHEN is_default THEN 1
 * END`, stored, with a plain unique index. It holds `1` on the default row
 * and NULL on every other, and NULLs being distinct in a unique index is
 * exactly what leaves the non-default rows unconstrained.
 *
 * `code` uniqueness is this task's own addition (F-17): docs/database.md
 * section 6.9 marks only `customers.code` unique, not this one.
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
        Schema::create('customer_groups', function (Blueprint $table) {
            IdentifierColumn::primary($table);
            $table->dateTime('created_at', 3);
            $table->dateTime('updated_at', 3);
            $table->dateTime('deleted_at', 3)->nullable();
            $table->string('code', 50);
            $table->string('name', 150);
            $table->decimal('discount_percent', 9, 4)->nullable();
            $table->boolean('credit_allowed')->default(false);
            $table->decimal('default_credit_limit', 18, 4)->nullable();
            $table->unsignedSmallInteger('default_payment_terms_days')->nullable();
            $table->boolean('is_default')->default(false);
            $table->unsignedTinyInteger('default_marker')->nullable()
                ->storedAs('CASE WHEN is_default THEN 1 END');
            $table->decimal('loyalty_multiplier', 9, 4)->nullable();
            $table->unsignedBigInteger('version')->default(1);

            $table->unique('code');
            $table->unique('default_marker');
        });
    }
};
