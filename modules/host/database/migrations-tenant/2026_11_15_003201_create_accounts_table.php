<?php

/*
 * The chart of accounts (docs/database.md section 6.10, T-032 AC-1 to AC-30,
 * plan section 4).
 *
 * **`control_marker` is the single-holder backstop, and the IN list below is
 * one of its two homes** (plan section 4): `Flexipos\Contracts\Finance\
 * ControlType::isSingle()` is the other, and `FinanceTenantSchemaTest` parses
 * this file and asserts the two are equal. It is the T-028 `default_marker`
 * shape (`customer_groups`): a stored generated column that holds the control
 * type of a LIVE row whose type may have only one live holder, and NULL on
 * every other row, with a plain unique index. NULLs being distinct in a
 * unique index is exactly what leaves the repeatable control types (cash,
 * bank, inventory, tax_payable, tax_receivable, and `none`) unconstrained, and
 * putting `deleted_at IS NULL` inside the expression is what lets a
 * soft-deleted holder coexist with its live successor (AC-17, D-3). On the
 * real engine the IN comparison is case-insensitive, which is the
 * conservative direction for a backstop; the handler's own pre-check is the
 * exact one.
 *
 * **`code` is unique across live and soft-deleted rows**: a soft-deleted
 * account keeps its code reserved (AC-3, AC-23). The stored form is
 * upper-case ASCII by construction (AC-2), so the engine's case-insensitive
 * collation can only ever agree with the application, never disagree.
 *
 * **`opening_balance`, `opening_balance_date` and `currency` are built and
 * never written** (D-9, AC-66): a balance column beside a ledger is two
 * sources of truth, and currency needs `currencies`, which is not built.
 * `opening_balance` is the only decimal this task declares, at the documented
 * (18, 4).
 *
 * `path` is the account ids joined by `/`, `depth` is 0 to 9 (D-12): ten
 * levels of 26-character ids is at most 269 characters, inside the 500 the
 * column holds.
 *
 * Foreign keys: `parent_id` onto this table (self-referencing, created with
 * the table, so an expand step may declare it) and the two audit columns onto
 * `employees`. No key onto `branches`: an account belongs to the tenant, not
 * to a branch.
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
        Schema::create('accounts', function (Blueprint $table) {
            IdentifierColumn::primary($table);
            $table->dateTime('created_at', 3);
            $table->dateTime('updated_at', 3);
            $table->dateTime('deleted_at', 3)->nullable();
            IdentifierColumn::nullable($table, 'created_by');
            IdentifierColumn::nullable($table, 'updated_by');

            $table->string('code', 20);
            $table->string('name', 150);
            $table->string('description', 500)->nullable();
            $table->string('type', 30);
            $table->string('subtype', 30)->nullable();
            IdentifierColumn::nullable($table, 'parent_id');
            $table->string('path', 500);
            $table->unsignedTinyInteger('depth');
            $table->string('normal_balance', 30);
            $table->boolean('is_postable')->default(true);
            $table->boolean('is_system')->default(false);
            $table->string('control_type', 30)->default('none');
            $table->string('control_marker', 30)->nullable()
                ->storedAs("CASE WHEN deleted_at IS NULL AND control_type IN ('ar', 'ap', 'grni', 'payroll_payable', 'retained_earnings') THEN control_type END");
            $table->char('currency', 3)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->decimal('opening_balance', 18, 4)->nullable();
            $table->date('opening_balance_date')->nullable();

            $table->unique('code');
            $table->unique('control_marker');
            $table->index('parent_id');
            $table->index('path');
            $table->index('type');
            $table->index('control_type');

            $table->foreign('parent_id')->references('id')->on('accounts');
            $table->foreign('created_by')->references('id')->on('employees');
            $table->foreign('updated_by')->references('id')->on('employees');
        });
    }
};
