<?php

/*
 * Customers (docs/database.md section 6.9, T-028 AC-6 to AC-15).
 *
 * **Four columns are deferred entirely, not built with no foreign key**
 * (D-6/D-19, F-2): `receivable_account_id` (`accounts`, Finance), plus the
 * three more this file's siblings defer. A foreign key onto an
 * already-existing table is a contract-phase migration under this
 * codebase's migration source rules, so each is added by the task that
 * ships its target table, as an expand step (the column) followed by a
 * separate contract step (the key) - never a single combined step.
 * `custom_fields` is not built either (F-1): `custom_field_definitions`
 * does not exist.
 *
 * **`[SYNC]` columns are built present but inert** (AC-6, F-14): `origin`
 * defaults to `online`, `sync_status` defaults to `synced`, every other
 * `[SYNC]` field defaults to null, and none carries a foreign key - section
 * 3.1's tag states none, and nothing in this task reads or branches on any
 * of them.
 *
 * **`credit_balance`, `loyalty_points`, `loyalty_tier` and
 * `merged_into_id` are maintained-only** (AC-8, D-8, D-29): only the
 * ledger/loyalty writers and `MergeCustomers` may change them, and only
 * inside the same transaction as the row that produces the new value.
 * `credit_limit` is `NOT NULL DEFAULT 0` (F-10/D-27): "no limit" is not a
 * representable value in this schema.
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
        Schema::create('customers', function (Blueprint $table) {
            IdentifierColumn::primary($table);
            $table->dateTime('created_at', 3);
            $table->dateTime('updated_at', 3);
            $table->dateTime('deleted_at', 3)->nullable();
            IdentifierColumn::nullable($table, 'created_by');
            IdentifierColumn::nullable($table, 'updated_by');

            // [SYNC], built inert (F-14): no foreign key on terminal_id or
            // device_id, and nothing in this task reads any of these seven.
            $table->string('origin', 30)->default('online');
            IdentifierColumn::nullable($table, 'terminal_id');
            IdentifierColumn::nullable($table, 'device_id');
            $table->unsignedBigInteger('client_seq')->nullable();
            $table->dateTime('client_created_at', 3)->nullable();
            $table->string('sync_status', 30)->default('synced');
            $table->dateTime('synced_at', 3)->nullable();

            IdentifierColumn::nullable($table, 'group_id');
            $table->string('code', 50)->nullable();
            $table->string('name', 150);
            $table->string('phone', 30)->nullable();
            $table->string('email', 191)->nullable();
            $table->json('address')->nullable();
            $table->date('dob')->nullable();
            $table->string('gender', 30)->nullable();
            $table->string('tax_no', 50)->nullable();
            $table->string('company_name', 150)->nullable();
            $table->char('currency', 3)->nullable();
            $table->boolean('credit_allowed')->default(false);
            $table->decimal('credit_limit', 18, 4)->default(0);
            $table->decimal('credit_balance', 18, 4)->default(0);
            $table->unsignedSmallInteger('payment_terms_days')->nullable();
            $table->decimal('loyalty_points', 18, 4)->default(0);
            $table->string('loyalty_tier', 50)->nullable();
            $table->decimal('opening_balance', 18, 4)->default(0);
            $table->string('status', 30)->default('active');
            $table->string('blocked_reason', 255)->nullable();
            IdentifierColumn::nullable($table, 'possible_duplicate_of');
            IdentifierColumn::nullable($table, 'merged_into_id');
            $table->dateTime('first_sale_at', 3)->nullable();
            $table->dateTime('last_sale_at', 3)->nullable();
            $table->decimal('lifetime_value', 18, 4)->default(0);
            $table->text('notes')->nullable();
            $table->dateTime('client_updated_at', 3)->nullable();
            $table->unsignedBigInteger('version')->default(1);

            $table->unique('code');
            $table->index('phone');
            $table->index('email');
            $table->index('name');
            $table->index('group_id');

            $table->foreign('group_id')->references('id')->on('customer_groups');
            $table->foreign('possible_duplicate_of')->references('id')->on('customers');
            $table->foreign('merged_into_id')->references('id')->on('customers');
            $table->foreign('created_by')->references('id')->on('employees');
            $table->foreign('updated_by')->references('id')->on('employees');
        });

        JsonValidity::enforce('customers', 'address');
    }
};
