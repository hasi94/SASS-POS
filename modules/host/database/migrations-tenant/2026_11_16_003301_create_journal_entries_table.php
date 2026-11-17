<?php

/*
 * Journal entries (docs/database.md section 6.10, T-033 AC-5, AC-32, AC-33a,
 * AC-34 to AC-45, AC-75 to AC-80).
 *
 * One row per posted entry, written by exactly one class
 * (`Flexipos\Finance\Journal\JournalWriter`, AC-5) and never deleted (AC-47).
 * There is no `deleted_at`, no tenant column (the database is the boundary),
 * no currency and no `plugin_name`.
 *
 * **`source_purpose_key` is the idempotency key's third part, and `'-'` is its
 * sentinel.** The key is `unique(source_type, source_id, source_purpose_key)`.
 * A nullable `source_purpose` placed in a unique key directly would let two
 * rows with a null purpose coexist (NULLs are distinct on both engines,
 * AC-39), so the key column is stored `COALESCE(source_purpose, '-')`.
 * `docs/database.md` section 3.3 requires the sentinel of a column that is not
 * an identifier to be a value the application rejects on write: AC-8 limits a
 * purpose to `[a-z][a-z0-9_]{0,29}`, so `'-'` can never be a stored purpose
 * and can never collide with one. `source_id` stays a plain nullable column:
 * a `manual` or `opening` entry without one escapes the key by design (AC-41).
 *
 * **Every key is declared with the table** (T-033 D-24): the table is new, so
 * an expand step may carry its own six foreign keys, all RESTRICT. The one
 * key that points INTO this table from an existing one,
 * `fiscal_years.retained_earnings_entry_id`, is a separate contract step
 * (`003304`). `balanced` is the entry-level CHECK `total_debit =
 * total_credit`; like every CHECK here it is engine-only (the stand-in cannot
 * express it) and its enforcement is `[ENGINE]`, NOT VERIFIED under
 * ENGINE_HOLD.
 *
 * `payload_fingerprint` is the lower-case hex SHA-256 of the normalised
 * payload (AC-35); `reversal_of_id` and `reversed_by_id` are the two ends of a
 * reversal pair.
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
        Schema::create('journal_entries', function (Blueprint $table) {
            IdentifierColumn::primary($table);
            $table->string('number', 30);
            $table->date('entry_date');
            IdentifierColumn::required($table, 'period_id');
            IdentifierColumn::nullable($table, 'branch_id');
            $table->string('source_type', 30);
            IdentifierColumn::nullable($table, 'source_id');
            $table->string('source_purpose', 30)->nullable();
            $table->string('source_purpose_key', 30)
                ->storedAs("COALESCE(source_purpose, '-')");
            $table->string('status', 30);
            $table->decimal('total_debit', 18, 4);
            $table->decimal('total_credit', 18, 4);
            $table->string('description', 500)->nullable();
            IdentifierColumn::nullable($table, 'reversal_of_id');
            IdentifierColumn::nullable($table, 'reversed_by_id');
            $table->char('payload_fingerprint', 64);
            $table->dateTime('posted_at', 3);
            IdentifierColumn::nullable($table, 'posted_by');
            $table->boolean('is_auto')->default(false);
            IdentifierColumn::nullable($table, 'created_by');
            $table->dateTime('created_at', 3);
            $table->dateTime('updated_at', 3);

            $table->unique('number');
            $table->unique(['source_type', 'source_id', 'source_purpose_key']);
            $table->index(['entry_date', 'number']);
            $table->index(['period_id', 'status']);

            $table->foreign('period_id')->references('id')->on('fiscal_periods');
            $table->foreign('branch_id')->references('id')->on('branches');
            $table->foreign('posted_by')->references('id')->on('employees');
            $table->foreign('created_by')->references('id')->on('employees');
            $table->foreign('reversal_of_id')->references('id')->on('journal_entries');
            $table->foreign('reversed_by_id')->references('id')->on('journal_entries');
        });

        CheckConstraint::balanced('journal_entries', 'total_debit', 'total_credit');
    }
};
