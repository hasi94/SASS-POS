<?php

/*
 * Per-plugin tenant settings (docs/database.md section 6.20, T-019 AC-18).
 *
 * `value` holds `Illuminate\Support\Facades\Crypt::encryptString()`'s output
 * when `is_secret` is true (`Flexipos\PluginSdk\Support\PluginSettingsStore`
 * is the only reader and writer) - the same "encrypted at rest" shape as
 * other per-tenant secret columns.
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
        Schema::create('plugin_settings', function (Blueprint $table) {
            $table->string('plugin_name', 100);
            $table->string('key', 120);
            $table->text('value')->nullable();
            $table->boolean('is_secret')->default(false);
            IdentifierColumn::nullable($table, 'updated_by');
            $table->dateTime('created_at', 3);
            $table->dateTime('updated_at', 3);

            $table->primary(['plugin_name', 'key']);
            $table->foreign('updated_by')->references('id')->on('employees');
        });
    }
};
