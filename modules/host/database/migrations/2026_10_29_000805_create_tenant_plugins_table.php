<?php

/*
 * One tenant's enablement state for one plugin (docs/database.md section
 * 4.3, T-019 AC-14 to AC-17, AC-30 to AC-34).
 *
 * `schema_version` mirrors how far this plugin's tenant-side migrations
 * have run inside that tenant database (AC-38). `failure_count`,
 * `tripped_at` and `last_error` are the circuit breaker's own columns
 * (D-6, plan Ruling 12) - `Flexipos\PluginSdk\Breaker\CircuitBreaker` is
 * the only writer of any of the three. `installed_by` is nullable for the
 * same reason `plugin_tenant_access.granted_by` is.
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
        Schema::create('tenant_plugins', function (Blueprint $table) {
            IdentifierColumn::primary($table);
            IdentifierColumn::required($table, 'tenant_id');
            IdentifierColumn::required($table, 'plugin_id');
            IdentifierColumn::required($table, 'plugin_version_id');
            $table->string('status', 30);
            $table->unsignedBigInteger('schema_version')->default(0);
            IdentifierColumn::nullable($table, 'licence_id');
            $table->dateTime('installed_at', 3)->nullable();
            $table->dateTime('enabled_at', 3)->nullable();
            $table->dateTime('disabled_at', 3)->nullable();
            IdentifierColumn::nullable($table, 'installed_by');
            $table->integer('failure_count')->default(0);
            $table->dateTime('tripped_at', 3)->nullable();
            $table->text('last_error')->nullable();
            $table->dateTime('created_at', 3);
            $table->dateTime('updated_at', 3);

            $table->unique(['tenant_id', 'plugin_id']);
            $table->index('status');
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('plugin_id')->references('id')->on('plugins');
            $table->foreign('plugin_version_id')->references('id')->on('plugin_versions');
            $table->foreign('licence_id')->references('id')->on('licences');
            $table->foreign('installed_by')->references('id')->on('users');
        });
    }
};
