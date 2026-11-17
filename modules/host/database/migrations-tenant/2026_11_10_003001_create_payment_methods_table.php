<?php

/*
 * Payment methods (docs/database.md section 6.8, T-030 AC-16).
 *
 * `type` is the application enum `cash|card|digital|credit|voucher|bank|
 * loyalty|other`, `VARCHAR(30)`, validated exactly and lower-case in PHP.
 * `code` is unique. `surcharge_percent` and `rounding_rule` are stored and
 * not applied by this task (F-11).
 *
 * **Deferred columns are not built** (D-7, D-33, F-8): `financial_account_id`
 * (Finance, FP-260, owes expand plus contract), `gateway` and `settings`
 * (the gateway apparatus, D-7). A nullable, unkeyed column gives Finance
 * nothing, and the precedent (T-027 D-3, T-028 D-6, T-029 F-17) is to defer
 * such a column entirely.
 *
 * Soft-deleted. `version` is the optimistic-lock counter the other tenant
 * entities carry.
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
        Schema::create('payment_methods', function (Blueprint $table) {
            IdentifierColumn::primary($table);
            $table->dateTime('created_at', 3);
            $table->dateTime('updated_at', 3);
            $table->dateTime('deleted_at', 3)->nullable();
            $table->string('code', 50);
            $table->string('name', 150);
            $table->string('type', 30);
            $table->boolean('is_active')->default(true);
            $table->boolean('opens_drawer')->default(false);
            $table->boolean('requires_reference')->default(false);
            $table->boolean('allows_change')->default(false);
            $table->boolean('allows_refund')->default(true);
            $table->boolean('allows_partial')->default(true);
            $table->string('rounding_rule', 30)->nullable();
            $table->decimal('surcharge_percent', 9, 4)->nullable();
            $table->integer('sort_order')->default(0);
            $table->unsignedInteger('version')->default(1);

            $table->unique('code');
        });
    }
};
