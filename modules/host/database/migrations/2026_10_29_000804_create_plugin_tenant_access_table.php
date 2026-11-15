<?php

/*
 * A private-visibility plugin's grant to one tenant (docs/database.md
 * section 4.3, T-019 AC-11).
 *
 * `granted_by` is nullable: a console action may have no platform user
 * behind it (plan section 4, -> ba informational).
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
        Schema::create('plugin_tenant_access', function (Blueprint $table) {
            IdentifierColumn::required($table, 'plugin_id');
            IdentifierColumn::required($table, 'tenant_id');
            IdentifierColumn::nullable($table, 'granted_by');
            $table->dateTime('created_at', 3);
            $table->dateTime('updated_at', 3);

            $table->primary(['plugin_id', 'tenant_id']);
            $table->foreign('plugin_id')->references('id')->on('plugins');
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('granted_by')->references('id')->on('users');
        });
    }
};
