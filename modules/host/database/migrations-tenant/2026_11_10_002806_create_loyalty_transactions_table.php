<?php

/*
 * Loyalty transactions (docs/database.md section 6.9, T-028 AC-27 to
 * AC-33).
 *
 * **Append-only** (AC-27, D-3), the same shape as `customer_ledger`:
 * `balance_after` is the running `customers.loyalty_points` at the moment
 * this row was written, maintained by the one writer this task ships
 * (`Loyalty\LoyaltyWriter`).
 *
 * **`[SYNC]` columns are built present but inert**, the identical shape
 * `customers` carries (AC-6, F-14).
 *
 * **`sale_id` and its documented `unique (sale_id, type) where sale_id
 * not null` idempotency key are deferred entirely** (D-6/D-19, F-2):
 * `sales` (FP-233) does not exist. Until then, `RecordLoyaltyTransaction`
 * accepts an optional caller-supplied `id` (this row's own primary key) as
 * its own replay key (F-15, AC-33) - no extra column is needed for that.
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
        Schema::create('loyalty_transactions', function (Blueprint $table) {
            IdentifierColumn::primary($table);

            // [SYNC], built inert (F-14): no foreign key on terminal_id or
            // device_id, and nothing in this task reads any of these seven.
            $table->string('origin', 30)->default('online');
            IdentifierColumn::nullable($table, 'terminal_id');
            IdentifierColumn::nullable($table, 'device_id');
            $table->unsignedBigInteger('client_seq')->nullable();
            $table->dateTime('client_created_at', 3)->nullable();
            $table->string('sync_status', 30)->default('synced');
            $table->dateTime('synced_at', 3)->nullable();

            IdentifierColumn::required($table, 'customer_id');
            IdentifierColumn::required($table, 'program_id');
            $table->string('type', 30);
            $table->decimal('points', 18, 4);
            $table->decimal('value', 18, 4)->nullable();
            $table->decimal('balance_after', 18, 4);
            $table->dateTime('expires_at', 3)->nullable();
            IdentifierColumn::nullable($table, 'employee_id');
            $table->text('notes')->nullable();
            $table->dateTime('created_at', 3);

            $table->index(['customer_id', 'created_at']);

            $table->foreign('customer_id')->references('id')->on('customers');
            $table->foreign('program_id')->references('id')->on('loyalty_programs');
            $table->foreign('employee_id')->references('id')->on('employees');
        });
    }
};
