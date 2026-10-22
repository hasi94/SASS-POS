<?php

/*
 * The shop's branches (T-014, FP-209; docs/database.md section 6.1).
 *
 * A branch is one trading site of the tenant. T-010 shipped every branch-scoped
 * grant against branch ids that named nothing (its D-1); this is the table
 * those ids name. **No `tenant_id`**: the database is the tenant boundary.
 *
 * **`code` is unique and so is held forever.** A branch is deactivated and
 * never deleted (T-014 D-4), so a soft-deleted row (`deleted_at`, created and
 * unused until a delete rule is decided) still owns its code, and the unique
 * key counts it.
 *
 * **One key on this table belongs to the contract file, and this file says
 * which.** `default_location_id` names a `locations` row and `locations` is
 * created by the next file, so a key here would reference a table that does
 * not exist yet. The column is created unguarded and the foreign-key
 * migration that follows the five table files adds the key (T-014 D-32).
 * Between the two runs the column is unguarded, which is harmless: the
 * application requires the top schema version before it serves a tenant, so
 * nothing writes it before the key exists (T-014 Ruling 2). The four branch
 * columns T-010 and T-008 left unguarded (`employee_roles.branch_id` and the
 * others) get their keys in that same later file, because adding a key to a
 * table created earlier is a contract step by the tree's own rule.
 *
 * `created_by` and `updated_by` name the employee who made the change and are
 * keyed here: `employees` is created by an earlier file, so the key can be
 * part of this table's creation.
 *
 * `address` is JSON with a validity check where the engine has one. Columns
 * the design lists and nothing in FP-209 reads are not created (D-10):
 * `cost_centre_account_id` waits for an accounts table, and custom fields for
 * their own task. No `down()`: tenant migrations are forward-only (T-008 D-8).
 *
 * @phase expand
 */

use Flexipos\Kernel\Schema\Support\IdentifierColumn;
use Flexipos\Kernel\Schema\Support\JsonValidity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            IdentifierColumn::primary($table);
            $table->string('code', 10);
            $table->string('name', 100);
            $table->json('address')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email', 191)->nullable();
            $table->string('timezone', 64);
            $table->char('currency', 3);
            $table->string('tax_registration_no', 50)->nullable();
            $table->string('business_type', 30)->nullable();
            $table->date('opening_date')->nullable();
            IdentifierColumn::nullable($table, 'default_location_id');
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            IdentifierColumn::nullable($table, 'created_by');
            IdentifierColumn::nullable($table, 'updated_by');
            $table->dateTime('created_at', 3);
            $table->dateTime('updated_at', 3);
            $table->dateTime('deleted_at', 3)->nullable();

            $table->unique('code');
            $table->foreign('created_by')->references('id')->on('employees');
            $table->foreign('updated_by')->references('id')->on('employees');
        });

        JsonValidity::enforce('branches', 'address');
    }
};
