<?php

/*
 * Discounts (docs/database.md section 6.4, T-029 AC-19 to AC-21).
 *
 * `type` is the application enum `percentage|fixed|buy_x_get_y` and `scope` is
 * `item|bill`, both `VARCHAR(30)`. **`value` is one `DECIMAL(18,4)` for both a
 * percentage and a money amount** (D-10): reusing the money pair keeps the
 * set of allowed precision pairs at four. `min_amount`, `max_discount` and
 * `approval_threshold` are money, nullable, and the same pair.
 *
 * `applies_to` is JSON `{products: [...], categories: [...], brands: [...]}`,
 * stored as supplied and never existence-validated (the engine is its only
 * reader), guarded by `JsonValidity`'s engine-only check. `requires_approval`
 * and `approval_threshold` are stored data only: no workflow is invoked by
 * this task (D-8); `workflow_definition_id` is existence-checked and keyed.
 *
 * `used_count` defaults to 0 and is maintained-only: a create or update that
 * supplies it is refused, and nothing in this task increments it (Sales owns
 * that, FP-233).
 *
 * **`sales_discount_account_id` is not built** (D-7 as reversed by F-17):
 * `accounts` (Finance, FP-260) does not exist, and a deferred column is
 * deferred entirely. Finance owes expand plus contract for it (the key only,
 * nullable).
 *
 * Soft-deleted, with the `[BY]` audit pair keyed onto `employees`.
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
        Schema::create('discounts', function (Blueprint $table) {
            IdentifierColumn::primary($table);
            $table->dateTime('created_at', 3);
            $table->dateTime('updated_at', 3);
            $table->dateTime('deleted_at', 3)->nullable();
            IdentifierColumn::nullable($table, 'created_by');
            IdentifierColumn::nullable($table, 'updated_by');
            $table->string('code', 50);
            $table->string('name', 150);
            $table->string('type', 30);
            $table->decimal('value', 18, 4);
            $table->string('scope', 30);
            $table->json('applies_to')->nullable();
            $table->decimal('min_amount', 18, 4)->nullable();
            $table->decimal('max_discount', 18, 4)->nullable();
            $table->boolean('requires_approval')->default(false);
            $table->decimal('approval_threshold', 18, 4)->nullable();
            IdentifierColumn::nullable($table, 'workflow_definition_id');
            $table->dateTime('starts_at', 3)->nullable();
            $table->dateTime('ends_at', 3)->nullable();
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('used_count')->default(0);
            $table->boolean('is_active')->default(true);

            $table->unique('code');

            $table->foreign('workflow_definition_id')->references('id')->on('workflow_definitions');
            $table->foreign('created_by')->references('id')->on('employees');
            $table->foreign('updated_by')->references('id')->on('employees');
        });

        JsonValidity::enforce('discounts', 'applies_to');
    }
};
