<?php

/*
 * Sale tenders (docs/database.md section 6.7, T-030 AC-11 to AC-15).
 *
 * One row per payment taken against a sale. `method_code` and `method_type`
 * are **snapshots** of the payment method at tender time. `amount` is signed:
 * positive on a sale, negative on a refund (D-43). `tendered` and
 * `change_given` carry the cash handed over and the change returned. The row
 * `id` equals the POS tender ULID (section 6.8), so a replay is idempotent.
 *
 * `payment_status` is the application enum `pending|recorded|failed|refunded`,
 * `VARCHAR(30)`, defaulting to `recorded`.
 *
 * **Deferred columns are not built** (D-7, D-33, F-8): `gateway_transaction_id`
 * and `payment_id` belong to the payments and gateway apparatus, which does not
 * exist. `sale_id` cascades.
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
        Schema::create('sale_tenders', function (Blueprint $table) {
            IdentifierColumn::primary($table);
            IdentifierColumn::required($table, 'sale_id');
            IdentifierColumn::required($table, 'payment_method_id');
            $table->string('method_code', 50);
            $table->string('method_type', 30);
            $table->decimal('amount', 18, 4);
            $table->decimal('tendered', 18, 4)->nullable();
            $table->decimal('change_given', 18, 4)->default(0);
            $table->string('reference', 100)->nullable();
            $table->string('payment_status', 30)->default('recorded');
            $table->dateTime('paid_at', 3);

            $table->index('sale_id');
            $table->index(['method_type', 'paid_at']);

            $table->foreign('sale_id')->references('id')->on('sales')->cascadeOnDelete();
            $table->foreign('payment_method_id')->references('id')->on('payment_methods');
        });
    }
};
