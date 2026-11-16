<?php

/*
 * Fiscal periods (docs/database.md section 6.10, T-032 AC-31, AC-38 to AC-50).
 *
 * **No `created_at` or `updated_at`** (F-18): section 6.10 gives this table no
 * `[TS]`, so a period rename records no row timestamp and the audit row is
 * the record of it (AC-65).
 *
 * **`unique(start_date)` is the overlap backstop** (plan Ruling 6): every
 * period starts on the first of a month, so two overlapping years always share
 * a period start date, and the engine refuses the second year's periods
 * whatever order two simultaneous creates run in (AC-35). It is also the
 * index the period gate reads by (AC-47: one indexed read).
 *
 * `fiscal_year_id` cascades: deleting a year takes its periods with it
 * (AC-37), and a year is only ever deleted after every period is open and
 * `FiscalYearUsage` reports no entries.
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
        Schema::create('fiscal_periods', function (Blueprint $table) {
            IdentifierColumn::primary($table);
            IdentifierColumn::required($table, 'fiscal_year_id');
            $table->unsignedTinyInteger('sequence');
            $table->string('name', 50);
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status', 30)->default('open');
            $table->dateTime('closed_at', 3)->nullable();
            IdentifierColumn::nullable($table, 'closed_by');

            $table->unique(['fiscal_year_id', 'sequence']);
            $table->unique('start_date');

            $table->foreign('fiscal_year_id')->references('id')->on('fiscal_years')->cascadeOnDelete();
            $table->foreign('closed_by')->references('id')->on('employees');
        });
    }
};
