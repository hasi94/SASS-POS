<?php

/*
 * One row per migration run across tenants (docs/database.md section 4.6).
 *
 * The counters (total, pending, running, done, failed, skipped) are what makes
 * "one failing tenant fails alone" observable: a run that ends
 * `completed_with_failures` is a normal outcome, not an error, and the operator
 * needs the split rather than a single status.
 *
 * `selector` is JSON - tenant ids, server ids or filters - with an explicit
 * validity check, because JSON is text on MariaDB.
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
        Schema::create('tenant_migration_runs', function (Blueprint $table) {
            IdentifierColumn::primary($table);
            $table->bigInteger('target_version');
            IdentifierColumn::nullable($table, 'initiated_by');
            $table->string('mode', 30);
            $table->json('selector')->nullable();
            $table->unsignedSmallInteger('concurrency')->default(1);
            $table->string('status', 30);
            $table->integer('total')->default(0);
            $table->integer('pending')->default(0);
            $table->integer('running')->default(0);
            $table->integer('done')->default(0);
            $table->integer('failed')->default(0);
            $table->integer('skipped')->default(0);
            $table->dateTime('started_at', 3)->nullable();
            $table->dateTime('finished_at', 3)->nullable();
            $table->string('aborted_reason', 255)->nullable();
            $table->dateTime('created_at', 3);
            $table->dateTime('updated_at', 3);

            $table->index('status');
            $table->foreign('initiated_by')->references('id')->on('users');
        });

        JsonValidity::enforce('tenant_migration_runs', 'selector');
    }
};
