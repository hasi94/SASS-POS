<?php

/*
 * Fiscal years (docs/database.md section 6.10, T-032 AC-31 to AC-37, AC-45,
 * AC-46).
 *
 * A year is twelve calendar months, from a first-of-month start to the day
 * before the same day a year later (D-8). `start_date` is unique, and so is
 * `name`; the name's case-insensitive uniqueness is the handler's
 * `lower(trim())` comparison with this index behind it (D-26), because the
 * stand-in compares case-sensitively and the engine pads.
 *
 * **`retained_earnings_entry_id` is a plain nullable `CHAR(26)` with no
 * foreign key** (F-14, D-28): `journal_entries` does not exist, and FP-261
 * owes the key. `status` holds `open` or `closed`; the `closing` value the
 * documents name is never entered by this task (D-5).
 *
 * `closed_by` references the employee who closed the year.
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
        Schema::create('fiscal_years', function (Blueprint $table) {
            IdentifierColumn::primary($table);
            $table->dateTime('created_at', 3);
            $table->dateTime('updated_at', 3);
            $table->string('name', 50);
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status', 30)->default('open');
            $table->dateTime('closed_at', 3)->nullable();
            IdentifierColumn::nullable($table, 'closed_by');
            IdentifierColumn::nullable($table, 'retained_earnings_entry_id');

            $table->unique('name');
            $table->unique('start_date');

            $table->foreign('closed_by')->references('id')->on('employees');
        });
    }
};
