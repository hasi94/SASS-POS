<?php

/*
 * FX-5: a destructive contract step (T-008 step 8).
 *
 * AC-59's and D-10's input: while `schema.backups.available` is false the
 * runner refuses to plan a run that would apply this file, at plan time, before
 * a single job row is written and before any tenant connection is opened.
 *
 * Destructive is read from the **code**, never declared: a file says what phase
 * it is in, but whether it destroys something is a property of its statements,
 * and this is the one case where a file that lied would be expensive. Dropping
 * a column from a table this migration did not create is what makes
 * `MigrationCatalogue::isDestructive` true here, and FX-4 - a contract step
 * that adds - is the control that keeps the refusal keyed on the flag rather
 * than on the phase.
 *
 * It is never applied in anger by any test: step 9's red phase asserts zero job
 * rows and zero connections, which is what proves the refusal happens before
 * anything runs. Letting this file loose to see what happens would be proving
 * the refusal by doing the thing the refusal exists to prevent.
 *
 * @phase contract
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fx_projection_counts', function (Blueprint $table) {
            $table->dropColumn('scratch_note');
        });
    }
};
