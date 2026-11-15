<?php

/*
 * FX-1: a later migration that adds a new table (T-008 step 8).
 *
 * This is the reversal path for D-1, which keeps a projection table out of the
 * baseline: a table added after the baseline reaches every tenant through the
 * runner, and AC-38 is the proof that the route works without the runner's own
 * code being changed to allow it.
 *
 * It also carries AC-39's observation. The criterion is that **no tenant
 * database is touched before the plan is written**, and the only input at which
 * that is distinguishable is from inside the first tenant's `up()`: the job
 * rows it can see must already number one per selected tenant. The read itself
 * is taken by the probe the test installs, never by this file - a migration
 * that named the master connection would violate AC-37, and this file is also
 * one of the controls proving that rule silent on a clean fixture.
 *
 * `scratch_note` exists for FX-5 to drop, which is the only reason it is here.
 *
 * @phase expand
 */

use Flexipos\Kernel\Schema\Support\IdentifierColumn;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\TenantMigrations\FixtureMigrationLog;

return new class extends Migration
{
    public function up(): void
    {
        FixtureMigrationLog::observe('fx1.jobs_seen');
        FixtureMigrationLog::record('fx1.ran_on', Schema::getConnection()->getDatabaseName());

        Schema::create('fx_projection_counts', function (Blueprint $table) {
            IdentifierColumn::primary($table);
            $table->string('aggregate_type', 60);
            $table->unsignedBigInteger('counted')->default(0);
            $table->string('scratch_note', 60)->nullable();
            $table->dateTime('created_at', 3);
        });
    }
};
