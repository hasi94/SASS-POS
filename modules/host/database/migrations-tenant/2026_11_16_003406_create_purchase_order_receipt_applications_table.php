<?php

/*
 * Purchase order receipt applications (T-034 AC-68 to AC-80, D-44, D-45).
 *
 * One row per `ApplyReceivedQuantity` call, keyed by the **caller's** id so a
 * retried call replays instead of double-counting. The deltas are **signed**
 * (a correction is a negative delta), `received_base_delta` in base units (the
 * authority) and `received_quantity_delta` in the line's own unit.
 *
 * The row is append-only evidence: a reopen or a cancel of an order is refused
 * once any application exists, even one reversed to zero (D-45), and every
 * key here is RESTRICT, so an order, an order line or an employee referenced
 * by an application can never be deleted out from under it.
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
        Schema::create('purchase_order_receipt_applications', function (Blueprint $table) {
            IdentifierColumn::primary($table);
            IdentifierColumn::required($table, 'order_id');
            IdentifierColumn::required($table, 'order_item_id');
            $table->decimal('received_base_delta', 18, 4);
            $table->decimal('received_quantity_delta', 18, 4);
            IdentifierColumn::nullable($table, 'created_by');
            $table->dateTime('created_at', 3);

            $table->index('order_item_id');
            $table->index('order_id');

            $table->foreign('order_id')->references('id')->on('purchase_orders');
            $table->foreign('order_item_id')->references('id')->on('purchase_order_items');
            $table->foreign('created_by')->references('id')->on('employees');
        });
    }
};
