<?php

/*
 * Per-tenant overrides of what the plan allows (docs/database.md section 4.2).
 *
 * The pair is the primary key, and `flag_key` references `feature_flags`: a flag
 * nobody declared cannot be switched on for a tenant (AC-9). This table
 * overrides the plan, so the order of truth is tenant override, then plan
 * feature, then the registry default.
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
        Schema::create('tenant_feature_flags', function (Blueprint $table) {
            IdentifierColumn::required($table, 'tenant_id');
            $table->string('flag_key', 60);
            $table->boolean('enabled');
            $table->string('reason', 255)->nullable();
            IdentifierColumn::nullable($table, 'set_by');
            $table->dateTime('created_at', 3);
            $table->dateTime('updated_at', 3);

            $table->primary(['tenant_id', 'flag_key']);
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('flag_key')->references('key')->on('feature_flags');
            $table->foreign('set_by')->references('id')->on('users');
        });
    }
};
