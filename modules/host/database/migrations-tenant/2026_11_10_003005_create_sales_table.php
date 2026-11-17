<?php

/*
 * Sales (docs/database.md section 6.7, T-030 AC-1 to AC-31).
 *
 * One table for the sale and its refund: `type` is `sale|refund|exchange`
 * (only the first two are reachable, D-10), `status` is `draft|held|
 * pending_payment|completed|voided|refunded|partially_refunded|failed`,
 * `business_date_source` is `client|server|clamped`; all `VARCHAR(30)` with an
 * application enum. `number` is unique and NULL until completion (two NULLs
 * are accepted by both engines), and is unique across both types, which is why
 * the sale and refund sequences carry different prefixes.
 *
 * **Deferred columns are not built** (D-6, D-9, D-33, F-8): `journal_entry_id`
 * and `ar_document_id` (Finance), `sales_order_id` and `order_id` (the order
 * documents, D-10) and `custom_fields`. A nullable, unkeyed column gives their
 * owner nothing, so each owner adds its column, with its key, when it exists.
 *
 * `bill_discount_id` and `bill_discount_value` are built and never written
 * (D-35: bill-level discounts are deferred). `stock_applied`, `cost_total` and
 * `credit_checked` are the placeholders FP-234 and the credit path read.
 * `price_list_id` is built nullable and keyed; this task never forces one
 * (D-11). `exchange_rate` is the one `(18,8)` column.
 *
 * **`[SYNC]` columns are built present but inert** (D-28); `terminal_id` is the
 * documented foreign key and is nullable.
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
        Schema::create('sales', function (Blueprint $table) {
            IdentifierColumn::primary($table);
            $table->dateTime('created_at', 3);
            $table->dateTime('updated_at', 3);

            // [SYNC], built inert (D-28).
            $table->string('origin', 30)->default('online');
            IdentifierColumn::nullable($table, 'terminal_id');
            IdentifierColumn::nullable($table, 'device_id');
            $table->unsignedBigInteger('client_seq')->nullable();
            $table->dateTime('client_created_at', 3)->nullable();
            $table->string('sync_status', 30)->default('synced');
            $table->dateTime('synced_at', 3)->nullable();

            IdentifierColumn::required($table, 'branch_id');
            IdentifierColumn::required($table, 'location_id');
            IdentifierColumn::nullable($table, 'shift_id');
            IdentifierColumn::required($table, 'employee_id');
            IdentifierColumn::nullable($table, 'customer_id');
            $table->string('customer_name', 150)->nullable();
            $table->string('customer_phone', 30)->nullable();
            $table->string('customer_tax_no', 50)->nullable();
            $table->string('number', 30)->nullable();
            $table->string('type', 30);
            IdentifierColumn::nullable($table, 'reference_sale_id');
            $table->string('status', 30);
            $table->date('business_date');
            $table->string('business_date_source', 30);
            IdentifierColumn::nullable($table, 'price_list_id');
            $table->char('currency', 3);
            $table->decimal('exchange_rate', 18, 8)->default(1);
            $table->decimal('subtotal', 18, 4);
            $table->decimal('discount_total', 18, 4);
            $table->decimal('tax_total', 18, 4);
            $table->decimal('service_charge', 18, 4);
            $table->decimal('rounding', 18, 4);
            $table->decimal('total', 18, 4);
            $table->decimal('paid_total', 18, 4);
            $table->decimal('change_due', 18, 4);
            $table->decimal('balance_due', 18, 4)->default(0);
            IdentifierColumn::nullable($table, 'bill_discount_id');
            $table->decimal('bill_discount_value', 18, 4)->nullable();
            $table->string('discount_reason', 255)->nullable();
            IdentifierColumn::nullable($table, 'approval_request_id');
            $table->boolean('stock_applied')->default(false);
            $table->decimal('cost_total', 18, 4)->nullable();
            $table->boolean('credit_checked')->default(false);
            $table->text('notes')->nullable();
            IdentifierColumn::nullable($table, 'voided_by');
            $table->string('void_reason', 255)->nullable();
            $table->dateTime('voided_at', 3)->nullable();
            $table->dateTime('completed_at', 3)->nullable();
            $table->unsignedInteger('receipt_print_count')->default(0);

            $table->unique('number');
            $table->index(['branch_id', 'business_date']);
            $table->index(['customer_id', 'completed_at']);
            $table->index(['terminal_id', 'client_seq']);
            $table->index('status');
            $table->index('shift_id');
            $table->index(['employee_id', 'business_date']);

            $table->foreign('branch_id')->references('id')->on('branches');
            $table->foreign('location_id')->references('id')->on('locations');
            $table->foreign('terminal_id')->references('id')->on('terminals');
            $table->foreign('shift_id')->references('id')->on('cash_shifts');
            $table->foreign('employee_id')->references('id')->on('employees');
            $table->foreign('customer_id')->references('id')->on('customers');
            $table->foreign('reference_sale_id')->references('id')->on('sales');
            $table->foreign('price_list_id')->references('id')->on('price_lists');
            $table->foreign('bill_discount_id')->references('id')->on('discounts');
            $table->foreign('approval_request_id')->references('id')->on('approval_requests');
            $table->foreign('voided_by')->references('id')->on('employees');
        });
    }
};
