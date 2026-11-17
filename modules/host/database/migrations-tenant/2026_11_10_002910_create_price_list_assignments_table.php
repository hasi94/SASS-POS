<?php

/*
 * Price list assignments (docs/database.md section 6.4, T-029 AC-14).
 *
 * Attaches one price list to a branch, a customer group or a single customer.
 * `scope_type` is the application enum `branch|customer_group|customer`.
 *
 * **`scope_id` carries no foreign key, and the reason is structural** (D-24,
 * F-8): it is polymorphic - one column naming a row in one of three different
 * tables depending on `scope_type` - which is section 3.3's documented
 * exception for a `reference_type`/`reference_id` pair. It is not because any
 * target is missing. The create handler existence-checks all three targets
 * against their own tables instead, so a dangling assignment cannot be
 * written by the application.
 *
 * `priority` orders several lists attached to the same scope (D-16); the
 * unique key is the documented `(scope_type, scope_id, price_list_id)`.
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
        Schema::create('price_list_assignments', function (Blueprint $table) {
            IdentifierColumn::primary($table);
            IdentifierColumn::required($table, 'price_list_id');
            $table->string('scope_type', 30);
            IdentifierColumn::required($table, 'scope_id');
            $table->smallInteger('priority')->default(0);

            $table->unique(['scope_type', 'scope_id', 'price_list_id']);

            $table->foreign('price_list_id')->references('id')->on('price_lists');
        });
    }
};
