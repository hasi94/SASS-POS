<?php

/*
 * Effective-dated tax rates (docs/database.md section 6.4, T-029 AC-6, AC-7).
 *
 * **`rate` is a percentage, stored as typed** (D-15): `15.0000` means 15
 * percent. `DECIMAL(9,4)` is the documented percentage pair and cannot hold a
 * fraction such as 0.12345, so no fractional reading is offered.
 *
 * `effective_to` is exclusive: a row covers `[effective_from, effective_to)`,
 * and null means open-ended. The unique key on `(tax_id, effective_from)` is
 * the documented one; the range-overlap rule it cannot express is the create
 * handler's, taken under a lock on the parent tax row.
 *
 * Dates are `DATE`, not instants: a document's tax date is a branch-local
 * calendar day. A rate row is immutable once written apart from closing its
 * range, because documents already resolved against it.
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
        Schema::create('tax_rates', function (Blueprint $table) {
            IdentifierColumn::primary($table);
            $table->dateTime('created_at', 3);
            $table->dateTime('updated_at', 3);
            IdentifierColumn::required($table, 'tax_id');
            $table->decimal('rate', 9, 4);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('note', 255)->nullable();

            $table->unique(['tax_id', 'effective_from']);

            $table->foreign('tax_id')->references('id')->on('taxes');
        });
    }
};
