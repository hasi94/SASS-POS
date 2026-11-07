<?php

/*
 * One row per `tenant:provision` run (docs/database.md section 4.7, as built
 * for T-026/FP-206).
 *
 * Three columns beyond database.md's own table (plan Ruling 2): `input`
 * (the validated CLI options, no secrets - a resume needs none), `claimed_by`
 * and `heartbeat_at` (the compare-and-set lease a resume claims through).
 *
 * `type` is string(30) and only `create` is ever written today (D-14): the
 * other four lifecycles database.md names are separate backlog rows.
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
        Schema::create('tenant_provisioning_runs', function (Blueprint $table) {
            IdentifierColumn::primary($table);
            IdentifierColumn::nullable($table, 'tenant_id');
            $table->string('type', 30);
            IdentifierColumn::nullable($table, 'source_tenant_id');
            IdentifierColumn::nullable($table, 'target_server_id');
            $table->string('status', 30);
            $table->string('current_step', 40)->nullable();
            $table->json('input');
            $table->string('claimed_by', 100)->nullable();
            $table->dateTime('heartbeat_at', 3)->nullable();
            $table->dateTime('started_at', 3)->nullable();
            $table->dateTime('finished_at', 3)->nullable();
            $table->text('error')->nullable();
            IdentifierColumn::nullable($table, 'initiated_by');
            $table->dateTime('created_at', 3);
            $table->dateTime('updated_at', 3);

            $table->index('status');
            $table->index('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('source_tenant_id')->references('id')->on('tenants');
            $table->foreign('target_server_id')->references('id')->on('database_servers');
            $table->foreign('initiated_by')->references('id')->on('users');
        });

        JsonValidity::enforce('tenant_provisioning_runs', 'input');
    }
};
