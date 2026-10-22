<?php

/*
 * The places stock will live (T-014, FP-209; docs/database.md section 6.2):
 * a branch's store, a warehouse, and the four types FP-251 will add rules for.
 *
 * `branch_id` is nullable on purpose: null means the location belongs to no
 * branch, which is how a central warehouse is stored. It is keyed onto
 * `branches`, which the previous file creates, so the key is part of this
 * table's creation, and the unique key on `code` holds a code forever -
 * locations are deactivated and never deleted (T-014 D-4).
 *
 * `type` is a closed set validated by the application and stored as a string,
 * never a native enum (the tree's rule): `branch_store`, `warehouse`,
 * `transit`, `consignment`, `damaged`, `virtual`. Defaults here are the safe
 * ones the two creatable types share: negative stock off, purchasable on, not
 * sellable from. The application states every flag explicitly (D-11); the
 * defaults only keep a row written by anything else conservative.
 *
 * **`created_by` and `updated_by` are created here and keyed by the contract
 * file, and this file says so.** The spec lists them as nullable employee ids
 * and the key onto `employees` is a constraint the plan adds (T-014 Ruling
 * 10 (e), D-45); it ships in the single foreign-key migration with the other
 * deferred keys rather than as part of this table's creation. Between the two
 * runs they are unguarded, which is harmless for the reason the branches file
 * gives.
 *
 * `manager_employee_id` is keyed here: `employees` is created by an earlier
 * file. `address` is JSON with a validity check where the engine has one. No
 * `tenant_id`, no `down()` (T-008 D-8).
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
        Schema::create('locations', function (Blueprint $table) {
            IdentifierColumn::primary($table);
            IdentifierColumn::nullable($table, 'branch_id');
            $table->string('code', 10);
            $table->string('name', 100);
            $table->string('type', 30);
            $table->json('address')->nullable();
            IdentifierColumn::nullable($table, 'manager_employee_id');
            $table->boolean('allow_negative_stock')->default(false);
            $table->boolean('is_sellable_from')->default(false);
            $table->boolean('is_purchasable_to')->default(true);
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            IdentifierColumn::nullable($table, 'created_by');
            IdentifierColumn::nullable($table, 'updated_by');
            $table->dateTime('created_at', 3);
            $table->dateTime('updated_at', 3);
            $table->dateTime('deleted_at', 3)->nullable();

            $table->unique('code');
            $table->index(['branch_id', 'type']);
            $table->foreign('branch_id')->references('id')->on('branches');
            $table->foreign('manager_employee_id')->references('id')->on('employees');
        });

        JsonValidity::enforce('locations', 'address');
    }
};
