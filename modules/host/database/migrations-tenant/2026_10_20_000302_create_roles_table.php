<?php

/*
 * Named sets of permissions, per shop (docs/database.md section 6.2).
 *
 * **`key` is unique including soft-deleted rows, and that is deliberate**
 * (AC-19, AC-22): a deleted role keeps its key reserved. A role's key appears
 * in audit rows and in a shop's own vocabulary, so letting a later role reuse
 * `cashier` would silently re-point history at a different set of rights. The
 * key is therefore a plain unique index over a NOT NULL column, which covers
 * the soft-deleted rows for free - no sentinel is needed and none is used,
 * because NULL is not a value here.
 *
 * `is_system` marks the roles nobody may rename, re-permission or delete
 * (AC-21). There is exactly one today, `owner`, and it is created by the
 * registry sync rather than seeded here (AC-17): it has to hold every
 * registered key, and no migration knows what those are.
 *
 * `plugin_name` and `sort_order` are later and additive (AC-3).
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
        Schema::create('roles', function (Blueprint $table) {
            IdentifierColumn::primary($table);
            $table->string('key', 60);
            $table->string('name', 100);
            $table->string('description', 255)->nullable();
            $table->boolean('is_system')->default(false);
            $table->dateTime('created_at', 3);
            $table->dateTime('updated_at', 3);
            $table->dateTime('deleted_at', 3)->nullable();

            $table->unique('key');
        });
    }
};
