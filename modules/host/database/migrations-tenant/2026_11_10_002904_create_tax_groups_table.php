<?php

/*
 * Tax groups (docs/database.md section 6.4, T-029 AC-8, AC-10).
 *
 * A group is the named bundle a product references; `tax_group_taxes` names
 * which taxes belong to it and in what order. `is_inclusive` says whether the
 * prices of products in this group already contain the tax.
 *
 * **No `deleted_at`, on purpose** (F-6, D-22): section 6.4 documents this
 * table with the id and timestamp columns only, no soft-delete tag. A group
 * is retired by setting `is_active = false`, refused while an active product
 * still references it.
 *
 * **`default_marker` is the one-default-group backstop**, the shape
 * `customer_groups` already uses and deliberately not section 3.3's
 * sentinel: `CASE WHEN is_default THEN 1 END`, stored, with a plain unique
 * index. It holds `1` on the default row and NULL on every other, and NULLs
 * being distinct in a unique index is exactly what leaves the non-default
 * rows unconstrained. The application clears the previous default in the same
 * transaction; this key is what refuses a second default if it ever does not.
 *
 * `code` uniqueness is the documented key.
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
        Schema::create('tax_groups', function (Blueprint $table) {
            IdentifierColumn::primary($table);
            $table->dateTime('created_at', 3);
            $table->dateTime('updated_at', 3);
            $table->string('code', 50);
            $table->string('name', 150);
            $table->boolean('is_inclusive')->default(false);
            $table->boolean('is_default')->default(false);
            $table->unsignedTinyInteger('default_marker')->nullable()
                ->storedAs('CASE WHEN is_default THEN 1 END');
            $table->boolean('is_active')->default(true);

            $table->unique('code');
            $table->unique('default_marker');
        });
    }
};
