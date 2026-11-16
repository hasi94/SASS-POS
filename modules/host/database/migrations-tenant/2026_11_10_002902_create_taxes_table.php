<?php

/*
 * Taxes (docs/database.md section 6.4, T-029 AC-5).
 *
 * `type` is the application enum `percentage|fixed` and `applies_to` is
 * `sale|purchase|both`, both `VARCHAR(30)` (a native enum is a table rebuild
 * to extend). Neither has a column default: a tax states what it is, and the
 * application refuses a missing value. `is_compound`, `is_recoverable` and
 * `is_withholding` default to false, `is_active` to true.
 *
 * `type = fixed` together with `is_compound = true` is refused by the create
 * and update handlers (`fixed_cannot_compound`, D-15); it is not a database
 * constraint, for the same reason no other cross-column rule in this schema
 * is (D-14's application-layer posture).
 *
 * `tax_code` is the short code printed on an invoice and may be null. Taxes
 * are soft-deleted: a tax that was ever charged must stay readable for
 * reprints. Rates live in `tax_rates`, effective-dated, never on this row.
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
        Schema::create('taxes', function (Blueprint $table) {
            IdentifierColumn::primary($table);
            $table->dateTime('created_at', 3);
            $table->dateTime('updated_at', 3);
            $table->dateTime('deleted_at', 3)->nullable();
            IdentifierColumn::required($table, 'authority_id');
            $table->string('code', 50);
            $table->string('name', 150);
            $table->string('tax_code', 20)->nullable();
            $table->string('type', 30);
            $table->boolean('is_compound')->default(false);
            $table->string('applies_to', 30);
            $table->boolean('is_recoverable')->default(false);
            $table->boolean('is_withholding')->default(false);
            $table->boolean('is_active')->default(true);

            $table->unique('code');

            $table->foreign('authority_id')->references('id')->on('tax_authorities');
        });
    }
};
