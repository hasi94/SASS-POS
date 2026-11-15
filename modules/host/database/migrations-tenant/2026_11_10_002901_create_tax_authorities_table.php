<?php

/*
 * Tax authorities (docs/database.md section 6.4, T-029 AC-4).
 *
 * **Two documented columns are not built here, and a third is not built on
 * `discounts`** (D-7 as reversed by F-17): `payable_account_id`,
 * `receivable_account_id` (this table) and `sales_discount_account_id`
 * (`discounts`). `accounts` (Finance, FP-260) does not exist, and this task's
 * own rule is that a deferred column is deferred entirely - no key-less
 * placeholder an admin could fill with unvalidated ids that Finance would
 * then have to clean. Whichever task ships `accounts` owes the full expand,
 * backfill and contract (tighten to NOT NULL) for `payable_account_id`, and
 * expand plus contract (the key only, nullable) for the other two - exactly
 * as if this table had never been touched.
 *
 * `filing_frequency` is the application enum `monthly|quarterly|yearly`,
 * `VARCHAR(30)`, and may be null (a withholding body files nothing
 * periodically). `code` uniqueness is the documented key.
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
        Schema::create('tax_authorities', function (Blueprint $table) {
            IdentifierColumn::primary($table);
            $table->dateTime('created_at', 3);
            $table->dateTime('updated_at', 3);
            $table->string('code', 50);
            $table->string('name', 150);
            $table->char('country', 2)->nullable();
            $table->string('registration_no', 50)->nullable();
            $table->string('filing_frequency', 30)->nullable();

            $table->unique('code');
        });
    }
};
