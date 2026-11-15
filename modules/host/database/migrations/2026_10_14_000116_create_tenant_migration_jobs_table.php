<?php

/*
 * One row per tenant per run (docs/database.md section 4.6).
 *
 * This is the table the whole runner rides on, and three of its keys are load
 * bearing:
 *  - unique (`run_id`, `tenant_id`) - a tenant appears once in a run, which is
 *    what makes a resumed run safe to re-plan;
 *  - index (`run_id`, `status`) - the progress report;
 *  - index (`status`, `lock_expires_at`) - the claim statement rides exactly
 *    this one, and it is why the claim can be a single UPDATE rather than a
 *    select-then-write race.
 *
 * `run_id` is the **only** CASCADE in the master schema (AC-7): a job is a pure
 * child of its run and means nothing without it. Every other key here is left at
 * the engine default, RESTRICT.
 *
 * `claimed_by`, `claimed_at` and `lock_expires_at` are the lease: a worker that
 * dies holds the row until the lease expires, and `attempts` is what stops a job
 * that always fails from being retried for ever (D-20, three attempts).
 *
 * No `created_at`/`updated_at` pair: the design gives this table `[ID]` alone,
 * and the timestamps that mean anything operationally are the named ones -
 * claimed, started, finished.
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
        Schema::create('tenant_migration_jobs', function (Blueprint $table) {
            IdentifierColumn::primary($table);
            IdentifierColumn::required($table, 'run_id');
            IdentifierColumn::required($table, 'tenant_id');
            IdentifierColumn::required($table, 'server_id');
            $table->bigInteger('from_version')->nullable();
            $table->bigInteger('to_version');
            $table->string('status', 30);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('claimed_by', 64)->nullable();
            $table->dateTime('claimed_at', 3)->nullable();
            $table->dateTime('lock_expires_at', 3)->nullable();
            $table->dateTime('started_at', 3)->nullable();
            $table->dateTime('finished_at', 3)->nullable();
            $table->integer('duration_ms')->nullable();
            $table->string('error_class', 191)->nullable();
            $table->text('error_message')->nullable();
            $table->string('error_migration', 191)->nullable();
            $table->string('log_path', 255)->nullable();

            $table->unique(['run_id', 'tenant_id']);
            $table->index(['run_id', 'status']);
            $table->index(['status', 'lock_expires_at']);
            $table->foreign('run_id')->references('id')->on('tenant_migration_runs')->cascadeOnDelete();
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('server_id')->references('id')->on('database_servers');
        });
    }
};
