<?php

/*
 * Departments (docs/database.md section 6.12, T-038 AC-3 to AC-7, plan section 4).
 *
 * **`code` is unique across live and soft-deleted rows** (AC-7, D-7): a
 * soft-deleted department keeps its code reserved. The stored form is the
 * canonical upper-case ASCII form (AC-17), so the engine's case-insensitive
 * collation can only agree with the application.
 *
 * **Not built:** `cost_centre_account_id` (D-6). A department's name is
 * checked unique among live siblings by the handler, not by a key, because
 * the sibling group is `parent_id` plus a case-folded name.
 *
 * Foreign keys: `parent_id` onto this table (created with the table, so an
 * expand step may declare it), `manager_employee_id` onto `employees` and
 * `branch_id` onto `branches`.
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
        Schema::create('departments', function (Blueprint $table) {
            IdentifierColumn::primary($table);
            $table->string('code', 20);
            $table->string('name', 150);
            IdentifierColumn::nullable($table, 'parent_id');
            IdentifierColumn::nullable($table, 'manager_employee_id');
            IdentifierColumn::nullable($table, 'branch_id');
            $table->boolean('is_active')->default(true);
            $table->dateTime('created_at', 3);
            $table->dateTime('updated_at', 3);
            $table->dateTime('deleted_at', 3)->nullable();

            $table->unique('code');
            $table->index('parent_id');

            $table->foreign('parent_id')->references('id')->on('departments');
            $table->foreign('manager_employee_id')->references('id')->on('employees');
            $table->foreign('branch_id')->references('id')->on('branches');
        });
    }
};
