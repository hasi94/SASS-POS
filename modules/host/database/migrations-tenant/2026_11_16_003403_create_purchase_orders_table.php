<?php

/*
 * Purchase orders (docs/database.md section 6.10, T-034 AC-40 to AC-97).
 *
 * `status` is `draft|pending_approval|approved|sent|partially_received|
 * received|closed|cancelled`, a `VARCHAR(30)` with an application enum
 * (Ruling 4; `sent` ships in the enum and nothing enters it yet). `number` is
 * `PO` numbered from `DocumentNumbers`, unique and NOT NULL (D-10).
 *
 * `supplier_name`, `supplier_tax_no` and `payment_terms_days` are **snapshots**
 * of the supplier at the time the order was written, so a printed order
 * reproduces exactly after the supplier is edited. `exchange_rate` is the one
 * `(18,8)` column; every money column is `(18,4)`. `received_value` defaults to
 * 0 and is maintained by the receipt rollup.
 *
 * **`custom_fields` is not built** (D-3): `[CF]` was not built on `customers`
 * (T-028), so there is nothing to validate it against.
 *
 * `request_id` keys the request the order was converted from.
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
        Schema::create('purchase_orders', function (Blueprint $table) {
            IdentifierColumn::primary($table);
            $table->dateTime('created_at', 3);
            $table->dateTime('updated_at', 3);
            IdentifierColumn::nullable($table, 'created_by');
            IdentifierColumn::nullable($table, 'updated_by');

            $table->string('number', 30);
            IdentifierColumn::required($table, 'supplier_id');
            $table->string('supplier_name', 150);
            $table->string('supplier_tax_no', 50)->nullable();
            $table->unsignedSmallInteger('payment_terms_days');
            IdentifierColumn::required($table, 'branch_id');
            IdentifierColumn::required($table, 'location_id');
            IdentifierColumn::nullable($table, 'request_id');
            $table->date('order_date');
            $table->date('expected_date')->nullable();
            $table->string('status', 30);
            IdentifierColumn::nullable($table, 'workflow_instance_id');
            $table->char('currency', 3);
            $table->decimal('exchange_rate', 18, 8)->default(1);
            $table->decimal('subtotal', 18, 4);
            $table->decimal('discount_total', 18, 4);
            $table->decimal('tax_total', 18, 4);
            $table->decimal('shipping', 18, 4);
            $table->decimal('total', 18, 4);
            $table->decimal('received_value', 18, 4)->default(0);
            $table->string('terms', 1000)->nullable();
            $table->text('notes')->nullable();

            $table->unique('number');
            $table->index(['supplier_id', 'status']);
            $table->index(['branch_id', 'order_date']);
            $table->index('status');
            $table->index('request_id');

            $table->foreign('created_by')->references('id')->on('employees');
            $table->foreign('updated_by')->references('id')->on('employees');
            $table->foreign('supplier_id')->references('id')->on('suppliers');
            $table->foreign('branch_id')->references('id')->on('branches');
            $table->foreign('location_id')->references('id')->on('locations');
            $table->foreign('request_id')->references('id')->on('purchase_requests');
            $table->foreign('workflow_instance_id')->references('id')->on('workflow_instances');
        });
    }
};
