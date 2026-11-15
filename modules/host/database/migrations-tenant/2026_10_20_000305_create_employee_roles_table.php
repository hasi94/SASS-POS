<?php

/*
 * Which roles an employee holds, and at which branch (docs/database.md
 * section 6.2).
 *
 * **The unique key is built on a stored generated sentinel, and the reason is
 * a defect rather than a style** (section 3.3, D-10). `branch_id` NULL means
 * "all branches" - a *meaningful* value - and MySQL and MariaDB treat NULLs
 * as distinct in a unique index, so a plain key over
 * (employee_id, role_id, branch_id) would permit unlimited duplicates of
 * exactly the row that matters. `employee_roles` is one of the eleven sites
 * FP-180 names. The key is therefore over `branch_id_key`, which is
 * `COALESCE(branch_id, '-')`, stored; `'-'` is safe only because the column
 * is CHAR(26) and a ULID is never one character.
 *
 * MEASURED on the stand-in before this file was written (step 2): the second
 * null-branch row is refused by the sentinel key, a distinct branch id is
 * accepted alongside it, and the same specific branch id twice is refused -
 * while the identical inserts against a plain unique key over the nullable
 * column ACCEPT the duplicate. That contrast is AC-6's control, and without
 * it a green here would be equally consistent with the broken shape.
 *
 * **`branch_id` is unguarded, and FP-209 owes its foreign key** (D-1, D-30).
 * There is no `branches` table yet, so this column is CHAR(26), nullable, and
 * **nothing in the database stops a bad branch id here** until the branches
 * module adds the key as a contract-phase migration after proving the column
 * clean. The same is true of `employee_permissions.branch_id` and
 * `employee_branches.branch_id`. The application does not check branch
 * existence either, and does not pretend to: there is nothing to check
 * against.
 *
 * `granted_by` and `granted_at` are the audit of the assignment itself; the
 * full audit row is `audit_logs`'.
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
        Schema::create('employee_roles', function (Blueprint $table) {
            IdentifierColumn::primary($table);
            IdentifierColumn::required($table, 'employee_id');
            IdentifierColumn::required($table, 'role_id');
            IdentifierColumn::nullable($table, 'branch_id');
            IdentifierColumn::sentinel($table, 'branch_id', 'branch_id_key');
            IdentifierColumn::nullable($table, 'granted_by');
            $table->dateTime('granted_at', 3);

            $table->unique(['employee_id', 'role_id', 'branch_id_key']);
            // No cascade, and the design does not ask for one here: an
            // employee is soft-deleted, so the row this would fire on is one
            // nobody creates. `employee_permissions` and `employee_branches`
            // do cascade, because the design prints CASCADE for those two.
            $table->foreign('employee_id')->references('id')->on('employees');
            $table->foreign('role_id')->references('id')->on('roles');
            $table->foreign('granted_by')->references('id')->on('employees');
        });
    }
};
