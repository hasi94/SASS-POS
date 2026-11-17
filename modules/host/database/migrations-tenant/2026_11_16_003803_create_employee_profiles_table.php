<?php

/*
 * Employee profiles (docs/database.md section 6.12, T-038 AC-3, AC-6, plan
 * Ruling 2).
 *
 * **A one-to-one sidecar, keyed by `employee_id`** (D-1): the primary key is
 * also the foreign key onto `employees`, which stays the identity and access
 * table and gains no HR column (T-010 AC-3). An employee without a profile is
 * valid. The key onto `employees` is RESTRICT, the engine default: employees
 * are never hard-deleted.
 *
 * Sensitive columns are plain text (D-16); the closed classification lives in
 * `Flexipos\Hr\Profiles\ProfileFields`.
 *
 * **Not built (D-5):** the photo path, the salary, pay-frequency,
 * salary-structure, commission, sales-person, default-location, tax and
 * statutory-number columns.
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
        Schema::create('employee_profiles', function (Blueprint $table) {
            IdentifierColumn::primary($table, 'employee_id');
            IdentifierColumn::required($table, 'branch_id');
            IdentifierColumn::nullable($table, 'department_id');
            IdentifierColumn::nullable($table, 'designation_id');
            IdentifierColumn::nullable($table, 'reports_to_employee_id');
            $table->string('employment_type', 30);
            $table->date('joining_date');
            $table->date('confirmation_date')->nullable();
            $table->date('probation_end_date')->nullable();
            $table->date('resignation_date')->nullable();
            $table->date('last_working_date')->nullable();
            $table->string('exit_reason', 500)->nullable();
            $table->date('dob')->nullable();
            $table->string('gender', 30)->nullable();
            $table->string('marital_status', 30)->nullable();
            $table->string('nationality', 60)->nullable();
            $table->string('nic', 30)->nullable();
            $table->string('passport_no', 30)->nullable();
            $table->string('personal_email', 191)->nullable();
            $table->string('work_email', 191)->nullable();
            $table->string('phone', 30)->nullable();
            $table->json('emergency_contact')->nullable();
            $table->json('address')->nullable();
            $table->text('notes')->nullable();
            IdentifierColumn::nullable($table, 'created_by');
            IdentifierColumn::nullable($table, 'updated_by');
            $table->dateTime('created_at', 3);
            $table->dateTime('updated_at', 3);

            $table->index('branch_id');
            $table->index('department_id');
            $table->index('designation_id');
            $table->index('reports_to_employee_id');

            $table->foreign('employee_id')->references('id')->on('employees');
            $table->foreign('reports_to_employee_id')->references('id')->on('employees');
            $table->foreign('created_by')->references('id')->on('employees');
            $table->foreign('updated_by')->references('id')->on('employees');
            $table->foreign('branch_id')->references('id')->on('branches');
            $table->foreign('department_id')->references('id')->on('departments');
            $table->foreign('designation_id')->references('id')->on('designations');
        });
    }
};
