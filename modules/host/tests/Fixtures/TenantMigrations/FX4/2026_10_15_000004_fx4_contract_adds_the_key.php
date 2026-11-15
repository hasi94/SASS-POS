<?php

/*
 * FX-4: a contract step that depends on an expand version (T-008 step 8).
 *
 * AC-58's input: the key it adds is onto `fx_projection_counts`, which FX-1
 * created, so a tenant that has not reached FX-1's version cannot have FX-4
 * applied to it - which is exactly the plan-time refusal AC-58 asks for, with
 * the blocking tenants named and no job row written.
 *
 * It is also D-10's **positive** control (plan section 0): a contract step that
 * is *not* destructive must not be refused for being destructive. Adding a
 * foreign key drops nothing, renames nothing and changes nothing, so
 * `MigrationCatalogue::isDestructive` answers false for it - and an
 * implementation that refused on `phase = contract` instead of on the
 * destructive flag is red here rather than merely suspected.
 *
 * A foreign key onto a table that already existed is allowed **only** in a
 * contract step (AC-34), which is the other half of why this file is the shape
 * it is.
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
        Schema::table('fx_two_marker', function (Blueprint $table) {
            $table->foreign('count_id')->references('id')->on('fx_projection_counts');
        });
    }
};
