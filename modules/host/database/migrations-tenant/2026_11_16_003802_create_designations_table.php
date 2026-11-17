<?php

/*
 * Designations (docs/database.md section 6.12, T-038 AC-3 to AC-7, plan section 4).
 *
 * `code` is unique across live and soft-deleted rows (AC-7, D-7). A designation
 * may belong to one department (`department_id`), and the name is unique among
 * the live designations of the same department group, checked by the handler
 * (AC-33).
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
        Schema::create('designations', function (Blueprint $table) {
            IdentifierColumn::primary($table);
            $table->string('code', 20);
            $table->string('name', 150);
            IdentifierColumn::nullable($table, 'department_id');
            $table->unsignedTinyInteger('level');
            $table->boolean('is_active')->default(true);
            $table->dateTime('created_at', 3);
            $table->dateTime('updated_at', 3);
            $table->dateTime('deleted_at', 3)->nullable();

            $table->unique('code');
            $table->index('department_id');

            $table->foreign('department_id')->references('id')->on('departments');
        });
    }
};
