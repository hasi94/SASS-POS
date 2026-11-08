<?php

/*
 * Product categories, with a materialised path (docs/database.md section
 * 6.3, T-027 AC-1 to AC-5, AC-3a).
 *
 * `path` is the root-to-self ids joined by `/`, and `depth` is the number of
 * ancestors - both computed by `Flexipos\Catalog\Categories\CategoryPath`,
 * never supplied by the caller. `VARCHAR(500)` over 26-character ULID ids
 * caps the deepest legal `depth` at 17 (AC-3a, F-8): a create or a move that
 * would push any affected row past 500 characters is refused by name before
 * any write, which is `CreateCategory`'s/`UpdateCategory`'s job, not a
 * database constraint this column could express. `categories.
 * income_account_id`/`kitchen_station_id` are deferred (AC-6, D-3): `accounts`
 * (FP-260) and a kitchen-station table (FP-239) do not exist yet, and each
 * is added, expand plus contract, by the task that ships its owning table.
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
        Schema::create('categories', function (Blueprint $table) {
            IdentifierColumn::primary($table);
            $table->dateTime('created_at', 3);
            $table->dateTime('updated_at', 3);
            $table->dateTime('deleted_at', 3)->nullable();
            IdentifierColumn::nullable($table, 'parent_id');
            $table->string('code', 50);
            $table->string('name', 150);
            $table->string('path', 500);
            $table->unsignedTinyInteger('depth');
            $table->string('image_path', 255)->nullable();
            $table->string('colour', 20)->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);

            $table->unique('code');
            $table->index('path');
            $table->index('parent_id');

            $table->foreign('parent_id')->references('id')->on('categories');
        });
    }
};
