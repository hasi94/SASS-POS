<?php

/*
 * What each tenant is currently paying for (docs/database.md section 4.2).
 *
 * Billing invoices, lines and payments are not in this task: AC-1's seventeen
 * tables are the ones FP-203 ships, and the gateway-facing half belongs to the
 * billing row. `external_ref` is the seam it will use.
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
        Schema::create('subscriptions', function (Blueprint $table) {
            IdentifierColumn::primary($table);
            IdentifierColumn::required($table, 'tenant_id');
            IdentifierColumn::required($table, 'plan_id');
            $table->string('status', 30);
            $table->integer('quantity')->default(1);
            $table->decimal('unit_price', 18, 4);
            $table->char('currency', 3);
            $table->dateTime('starts_at', 3);
            $table->dateTime('current_period_start', 3)->nullable();
            $table->dateTime('current_period_end', 3)->nullable();
            $table->dateTime('trial_ends_at', 3)->nullable();
            $table->dateTime('cancel_at', 3)->nullable();
            $table->dateTime('cancelled_at', 3)->nullable();
            $table->string('cancellation_reason', 255)->nullable();
            $table->string('external_ref', 100)->nullable();
            $table->dateTime('created_at', 3);
            $table->dateTime('updated_at', 3);

            $table->index(['tenant_id', 'status']);
            $table->index('current_period_end');
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('plan_id')->references('id')->on('plans');
        });
    }
};
