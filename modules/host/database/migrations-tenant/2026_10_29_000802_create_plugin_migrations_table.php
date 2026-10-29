<?php

/*
 * Which of a plugin's own migration files have run, in this tenant
 * database (docs/database.md section 6.20, T-019 AC-38, AC-39).
 *
 * Per tenant database, so a plugin enabled for 40 tenants has 40
 * independent migration states, mirrored in master on
 * `tenant_plugins.schema_version` and run by
 * `Flexipos\PluginSdk\Migrations\PluginMigrationRunner` (section 9.6).
 *
 * @phase expand
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plugin_migrations', function (Blueprint $table) {
            $table->string('plugin_name', 100);
            $table->string('migration', 191);
            $table->integer('batch');
            $table->dateTime('ran_at', 3);

            $table->primary(['plugin_name', 'migration']);
        });
    }
};
