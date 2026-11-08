<?php

/*
 * Product brands (docs/database.md section 6.3, T-027 AC-7, AC-8).
 *
 * Both `code` and `name` are independently unique (confirmed per architect
 * review F-12: "code, name unique" reads as both columns, not one), so a
 * typo in either can only be fixed by `UpdateBrand`, never by delete and
 * recreate, since both unique keys hold their value forever across a soft
 * delete.
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
        Schema::create('brands', function (Blueprint $table) {
            IdentifierColumn::primary($table);
            $table->dateTime('created_at', 3);
            $table->dateTime('updated_at', 3);
            $table->dateTime('deleted_at', 3)->nullable();
            $table->string('code', 50);
            $table->string('name', 150);
            $table->string('logo_path', 255)->nullable();
            $table->boolean('is_active')->default(true);

            $table->unique('code');
            $table->unique('name');
        });
    }
};
