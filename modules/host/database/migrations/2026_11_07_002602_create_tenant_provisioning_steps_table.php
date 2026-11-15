<?php

/*
 * One row per step of a provisioning run, written whole at plan time
 * (docs/database.md section 4.7, as built for T-026/FP-206) - the same shape
 * T-008's tenant_migration_jobs already uses for one run's jobs.
 *
 * `(run_id, step)` is unique: a run never names the same step twice, which is
 * what makes a resumed run's "find my own step" lookup exact.
 *
 * @phase expand
 */

use Flexipos\Kernel\Schema\Support\IdentifierColumn;
use Flexipos\Kernel\Schema\Support\JsonValidity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_provisioning_steps', function (Blueprint $table) {
            IdentifierColumn::primary($table);
            IdentifierColumn::required($table, 'run_id');
            $table->string('step', 40);
            $table->smallInteger('sequence');
            $table->string('status', 30);
            $table->smallInteger('attempts')->default(0);
            $table->dateTime('started_at', 3)->nullable();
            $table->dateTime('finished_at', 3)->nullable();
            $table->json('output')->nullable();
            $table->text('error')->nullable();
            $table->dateTime('created_at', 3);
            $table->dateTime('updated_at', 3);

            $table->unique(['run_id', 'step']);
            $table->foreign('run_id')->references('id')->on('tenant_provisioning_runs')->cascadeOnDelete();
        });

        JsonValidity::enforce('tenant_provisioning_steps', 'output');
    }
};
