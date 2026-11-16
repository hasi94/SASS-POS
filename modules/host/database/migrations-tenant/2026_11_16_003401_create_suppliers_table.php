<?php

/*
 * Suppliers (docs/database.md section 6.10, T-034 AC-1 to AC-18).
 *
 * `code` is unique across **every** row, a soft-deleted one included, so a
 * deleted supplier keeps its code reserved (AC-3). `email` is VARCHAR(191) (D-32).
 * `credit_limit` is `NOT NULL DEFAULT 0` (D-32, the customers precedent):
 * "no limit" is not a representable value in this schema. `payment_terms_days`
 * is `SMALLINT UNSIGNED NOT NULL DEFAULT 0`. `bank_details` and `address` are
 * JSON, and `bank_details` is redacted from every audit image (D-9, AC-17).
 *
 * **Four columns are deferred entirely, not built with no foreign key** (D-3):
 * `custom_fields` (`[CF]` was not built on `customers` either, T-028),
 * `opening_balance`, `payable_account_id` and `default_expense_account_id`
 * (the accounts table and the payables ledger belong to Finance). A nullable,
 * unkeyed column gives its owner nothing, so each owner adds its column, with
 * its key, when its target exists.
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
        Schema::create('suppliers', function (Blueprint $table) {
            IdentifierColumn::primary($table);
            $table->dateTime('created_at', 3);
            $table->dateTime('updated_at', 3);
            $table->dateTime('deleted_at', 3)->nullable();
            IdentifierColumn::nullable($table, 'created_by');
            IdentifierColumn::nullable($table, 'updated_by');

            $table->string('code', 50);
            $table->string('name', 150);
            $table->string('legal_name', 150)->nullable();
            $table->string('contact_name', 100)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email', 191)->nullable();
            $table->json('address')->nullable();
            $table->string('tax_no', 50)->nullable();
            $table->char('currency', 3)->nullable();
            $table->unsignedSmallInteger('payment_terms_days')->default(0);
            $table->decimal('credit_limit', 18, 4)->default(0);
            $table->json('bank_details')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();

            $table->unique('code');
            $table->index('name');

            $table->foreign('created_by')->references('id')->on('employees');
            $table->foreign('updated_by')->references('id')->on('employees');
        });

        JsonValidity::enforce('suppliers', 'address', 'bank_details');
    }
};
