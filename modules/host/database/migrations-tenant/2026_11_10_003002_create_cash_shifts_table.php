<?php

/*
 * Cash shifts (docs/database.md section 6.7, T-030 AC-32 to AC-37).
 *
 * `status` is the application enum `open|closing|closed|reconciled`,
 * `VARCHAR(30)`. Everything the close computes (`expected_cash`,
 * `counted_cash`, `variance`, `gross`, `discounts`, `tax`, `net`,
 * `totals_by_method`, `sales_count`, `refunds_count`) is NULL until the
 * shift closes.
 *
 * **`open_terminal_key` is the one-open-shift-per-terminal backstop** (D-22),
 * the shape `customer_groups.default_marker` uses: `CASE WHEN status = 'open'
 * THEN terminal_id END`, stored, with a plain unique index. It holds the
 * terminal id on an `open` row and NULL on every other, and NULLs being
 * distinct in a unique index is exactly what leaves closed shifts
 * unconstrained. It is deliberately NOT section 3.3's `COALESCE` sentinel.
 *
 * **`[SYNC]` columns are built present but inert** (D-28), with no foreign
 * key on `device_id`; `terminal_id` is required here and keyed.
 *
 * **`journal_entry_id` is not built** (D-6, D-33, F-8): the general ledger is
 * Finance's (FP-260/FP-263), and a deferred column is deferred entirely.
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
        Schema::create('cash_shifts', function (Blueprint $table) {
            IdentifierColumn::primary($table);
            $table->dateTime('created_at', 3);
            $table->dateTime('updated_at', 3);

            // [SYNC], built inert (D-28): nothing in this task reads any of
            // these seven, and device_id carries no key.
            $table->string('origin', 30)->default('online');
            IdentifierColumn::required($table, 'terminal_id');
            IdentifierColumn::nullable($table, 'device_id');
            $table->unsignedBigInteger('client_seq')->nullable();
            $table->dateTime('client_created_at', 3)->nullable();
            $table->string('sync_status', 30)->default('synced');
            $table->dateTime('synced_at', 3)->nullable();

            IdentifierColumn::required($table, 'branch_id');
            IdentifierColumn::required($table, 'employee_id');
            $table->string('number', 30);
            $table->string('status', 30);
            $table->dateTime('opened_at', 3);
            $table->dateTime('closed_at', 3)->nullable();
            $table->decimal('opening_cash', 18, 4);
            $table->decimal('expected_cash', 18, 4)->nullable();
            $table->decimal('counted_cash', 18, 4)->nullable();
            $table->decimal('variance', 18, 4)->nullable();
            $table->decimal('gross', 18, 4)->nullable();
            $table->decimal('discounts', 18, 4)->nullable();
            $table->decimal('tax', 18, 4)->nullable();
            $table->decimal('net', 18, 4)->nullable();
            $table->json('totals_by_method')->nullable();
            $table->unsignedInteger('sales_count')->nullable();
            $table->unsignedInteger('refunds_count')->nullable();
            IdentifierColumn::nullable($table, 'closed_by');
            IdentifierColumn::nullable($table, 'reconciled_by');
            $table->text('notes')->nullable();
            IdentifierColumn::nullable($table, 'open_terminal_key')
                ->storedAs("CASE WHEN status = 'open' THEN terminal_id END");

            $table->unique('number');
            $table->unique('open_terminal_key');
            $table->index(['branch_id', 'opened_at']);
            $table->index(['terminal_id', 'status']);

            $table->foreign('branch_id')->references('id')->on('branches');
            $table->foreign('terminal_id')->references('id')->on('terminals');
            $table->foreign('employee_id')->references('id')->on('employees');
            $table->foreign('closed_by')->references('id')->on('employees');
            $table->foreign('reconciled_by')->references('id')->on('employees');
        });

        JsonValidity::enforce('cash_shifts', 'totals_by_method');
    }
};
