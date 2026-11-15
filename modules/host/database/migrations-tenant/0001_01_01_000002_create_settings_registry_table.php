<?php

/*
 * What a setting is: the registry modules and plugins declare at boot
 * (docs/database.md section 6.1).
 *
 * It exists before `settings` because `settings.key` references it: a value
 * cannot be stored for a setting nobody declared, which is AC-30's control (an
 * insert with an unknown key is refused).
 *
 * `default_value` and `validation` are JSON with an explicit validity check -
 * MariaDB's JSON is an alias for LONGTEXT and would otherwise accept anything.
 * `is_secret` marks a value the POS must not be sent; a secret *value* still
 * does not belong in a settings row.
 *
 * @phase expand
 */

use Flexipos\Kernel\Schema\Support\JsonValidity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings_registry', function (Blueprint $table) {
            $table->string('key', 120)->primary();
            $table->string('module', 40);
            $table->string('type', 30);
            $table->json('default_value')->nullable();
            $table->json('validation')->nullable();
            $table->boolean('sync_to_pos')->default(false);
            $table->boolean('is_secret')->default(false);
            $table->string('plugin_name', 100)->nullable();
        });

        JsonValidity::enforce('settings_registry', 'default_value', 'validation');
    }
};
