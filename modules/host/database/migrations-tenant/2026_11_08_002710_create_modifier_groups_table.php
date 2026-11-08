<?php

/*
 * Modifier groups (size, extras, cooking preference) - docs/database.md
 * section 6.3, T-027 AC-36, AC-36a.
 *
 * `max_select` null means unlimited (F-9: there is no sentinel integer for
 * "unlimited"); when set it must be `>= min_select`, checked by
 * `CreateModifierGroup`. Soft-deleting a group attached to a live product is
 * refused (AC-36a) - a reference check `DeleteModifierGroup` runs, not a
 * constraint this table expresses.
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
        Schema::create('modifier_groups', function (Blueprint $table) {
            IdentifierColumn::primary($table);
            $table->dateTime('created_at', 3);
            $table->dateTime('updated_at', 3);
            $table->dateTime('deleted_at', 3)->nullable();
            $table->string('name', 150);
            $table->unsignedSmallInteger('min_select')->default(0);
            $table->unsignedSmallInteger('max_select')->nullable();
            $table->boolean('is_required')->default(false);
            $table->integer('sort_order')->default(0);
        });
    }
};
