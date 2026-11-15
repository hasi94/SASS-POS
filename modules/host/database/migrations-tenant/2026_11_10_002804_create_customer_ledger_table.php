<?php

/*
 * The credit ledger (docs/database.md section 6.9, T-028 AC-19 to AC-24).
 *
 * **Append-only** (AC-19, D-2): no update or delete handler is ever built
 * against this table (step 16's source scan asserts it). `balance_after`
 * is the running `customers.credit_balance` at the moment this row was
 * written, maintained by the one writer this task ships
 * (`Ledger\CreditLedgerWriter`) inside the same transaction as the insert.
 *
 * **`ar_document_id` is deferred entirely** (D-6/D-19, F-2): `ar_documents`
 * (Finance) does not exist. Added by the task that ships it, as an expand
 * step (the column) followed by a separate contract step (the key).
 *
 * **The documented unique key is this task's own idempotency key**
 * (AC-21, F-4): `(reference_type, reference_id, type)`. No foreign key on
 * the reference pair (docs/database.md section 3.3) - the referenced
 * table varies by `reference_type` and does not always exist yet.
 *
 * **The `CHECK` is `[ENGINE]`** (AC-20, D-15): `CheckConstraint::oneSided`
 * mirrors `journal_lines`' own CHECK shape, exactly one of `debit`/`credit`
 * non-zero and neither negative. It issues nothing on SQLite, the suite's
 * own driver; the refusal itself stays NOT VERIFIED until G-1.
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
        Schema::create('customer_ledger', function (Blueprint $table) {
            IdentifierColumn::primary($table);
            IdentifierColumn::required($table, 'customer_id');
            IdentifierColumn::nullable($table, 'branch_id');
            $table->string('type', 30);
            $table->string('reference_type', 50);
            IdentifierColumn::required($table, 'reference_id');
            $table->decimal('debit', 18, 4)->default(0);
            $table->decimal('credit', 18, 4)->default(0);
            $table->decimal('balance_after', 18, 4);
            $table->char('currency', 3);
            IdentifierColumn::nullable($table, 'employee_id');
            $table->text('notes')->nullable();
            $table->dateTime('created_at', 3);

            $table->unique(['reference_type', 'reference_id', 'type']);
            $table->index(['customer_id', 'created_at']);

            $table->foreign('customer_id')->references('id')->on('customers');
            $table->foreign('branch_id')->references('id')->on('branches');
            $table->foreign('employee_id')->references('id')->on('employees');
        });

        CheckConstraint::oneSided('customer_ledger', 'debit', 'credit');
    }
};
