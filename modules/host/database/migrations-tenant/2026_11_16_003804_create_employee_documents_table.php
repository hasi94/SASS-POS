<?php

/*
 * Employee documents (docs/database.md section 6.12, T-038 AC-3, AC-6, AC-65
 * to AC-77, plan section 4).
 *
 * `file_path` holds a server-generated storage key of the form
 * tenant / `hr/employee-documents` / ULID (D-10), read and written only
 * through the kernel's document store. `original_name`, `mime`, `size_bytes`
 * and `checksum` are additive to the design row; `updated_at` and
 * `deleted_at` are the deviations stated in D-11, and `updated_by` is D-39.
 *
 * The key onto `employees` is RESTRICT rather than CASCADE: employees are
 * never hard-deleted.
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
        Schema::create('employee_documents', function (Blueprint $table) {
            IdentifierColumn::primary($table);
            IdentifierColumn::required($table, 'employee_id');
            $table->string('type', 30);
            $table->string('title', 150);
            $table->string('file_path', 255);
            $table->string('original_name', 255);
            $table->string('mime', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->char('checksum', 64);
            $table->date('issue_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->boolean('is_confidential')->default(false);
            $table->string('notes', 1000)->nullable();
            IdentifierColumn::nullable($table, 'created_by');
            IdentifierColumn::nullable($table, 'updated_by');
            $table->dateTime('created_at', 3);
            $table->dateTime('updated_at', 3);
            $table->dateTime('deleted_at', 3)->nullable();

            $table->index(['employee_id', 'type']);
            $table->index('expiry_date');

            $table->foreign('employee_id')->references('id')->on('employees');
            $table->foreign('created_by')->references('id')->on('employees');
            $table->foreign('updated_by')->references('id')->on('employees');
        });
    }
};
