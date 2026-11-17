<?php

/*
 * Journal lines (docs/database.md section 6.10, T-033 AC-12, AC-42 to AC-46,
 * AC-75 to AC-80).
 *
 * One row per line of a posted entry, inserted with its entry in one
 * statement (AC-46) and never updated or deleted. A line carries one side
 * only: `CheckConstraint::oneSided` states both conditions of AC-45 in one
 * constraint (`(debit = 0 OR credit = 0) AND debit >= 0 AND credit >= 0`,
 * T-033 D-34). Like every CHECK here it is engine-only, and its enforcement is
 * `[ENGINE]`, NOT VERIFIED under ENGINE_HOLD.
 *
 * No unit, quantity, currency, `project_ref`, `tax_id` or `tax_base` (AC-43):
 * the ledger is amounts and dimensions only.
 *
 * **Seven dimensions, five of them guarded.** `branch_id`, `location_id`,
 * `employee_id`, `customer_id` and `product_id` reference their tables.
 * `department_id` and `supplier_id` carry no key: `departments` and
 * `suppliers` do not exist yet (T-033 D-16), so those two are owed as
 * contract-phase keys by the tasks that build them, and the service checks
 * neither. `entry_id` cascades, and is the only cascade: an entry is never
 * deleted, so it matters only to a test and to a tenant teardown.
 *
 * @phase expand
 */

use Flexipos\Kernel\Schema\Support\CheckConstraint;
use Flexipos\Kernel\Schema\Support\IdentifierColumn;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_lines', function (Blueprint $table) {
            IdentifierColumn::primary($table);
            IdentifierColumn::required($table, 'entry_id');
            $table->unsignedSmallInteger('line_no');
            IdentifierColumn::required($table, 'account_id');
            $table->decimal('debit', 18, 4)->default(0);
            $table->decimal('credit', 18, 4)->default(0);
            $table->string('memo', 255)->nullable();
            IdentifierColumn::nullable($table, 'branch_id');
            IdentifierColumn::nullable($table, 'location_id');
            IdentifierColumn::nullable($table, 'department_id');
            IdentifierColumn::nullable($table, 'employee_id');
            IdentifierColumn::nullable($table, 'customer_id');
            IdentifierColumn::nullable($table, 'supplier_id');
            IdentifierColumn::nullable($table, 'product_id');
            $table->dateTime('created_at', 3);

            $table->unique(['entry_id', 'line_no']);
            $table->index(['account_id', 'entry_id']);
            $table->index('customer_id');
            $table->index('supplier_id');
            $table->index('employee_id');

            $table->foreign('entry_id')->references('id')->on('journal_entries')->cascadeOnDelete();
            $table->foreign('account_id')->references('id')->on('accounts');
            $table->foreign('branch_id')->references('id')->on('branches');
            $table->foreign('location_id')->references('id')->on('locations');
            $table->foreign('employee_id')->references('id')->on('employees');
            $table->foreign('customer_id')->references('id')->on('customers');
            $table->foreign('product_id')->references('id')->on('products');
        });

        CheckConstraint::oneSided('journal_lines', 'debit', 'credit');
    }
};
