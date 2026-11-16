<?php

/*
 * Cash movements (docs/database.md section 6.7, T-030 AC-33, AC-37).
 *
 * Drops, pay-ins, float, petty cash and bank deposits against an open shift.
 * `type` is the application enum `cash_in|cash_out|float_in|float_out|drop|
 * petty_cash|bank_deposit`, `VARCHAR(30)`. `amount` is always positive; the
 * type carries the direction. The row `id` is supplied by the caller and is
 * its own replay key (AC-37).
 *
 * **`shift_id` cascades**, as section 6.8 documents: a movement has no life
 * outside its shift. **`financial_account_id` is not built** (D-6, D-33,
 * F-8): where a drop or deposit went is Finance's (FP-260).
 *
 * **`[SYNC]` columns are built present but inert** (D-28), with no foreign
 * keys on `terminal_id` or `device_id`.
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
        Schema::create('cash_movements', function (Blueprint $table) {
            IdentifierColumn::primary($table);

            // [SYNC], built inert (D-28).
            $table->string('origin', 30)->default('online');
            IdentifierColumn::nullable($table, 'terminal_id');
            IdentifierColumn::nullable($table, 'device_id');
            $table->unsignedBigInteger('client_seq')->nullable();
            $table->dateTime('client_created_at', 3)->nullable();
            $table->string('sync_status', 30)->default('synced');
            $table->dateTime('synced_at', 3)->nullable();

            IdentifierColumn::required($table, 'shift_id');
            $table->string('type', 30);
            $table->decimal('amount', 18, 4);
            $table->string('reason', 255);
            $table->string('reference', 100)->nullable();
            IdentifierColumn::required($table, 'employee_id');
            IdentifierColumn::nullable($table, 'approved_by');
            $table->dateTime('created_at', 3);

            $table->index(['shift_id', 'created_at']);

            $table->foreign('shift_id')->references('id')->on('cash_shifts')->cascadeOnDelete();
            $table->foreign('employee_id')->references('id')->on('employees');
            $table->foreign('approved_by')->references('id')->on('employees');
        });
    }
};
