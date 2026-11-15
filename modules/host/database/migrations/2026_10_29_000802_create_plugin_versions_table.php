<?php

/*
 * One registered, immutable version of a plugin (docs/database.md section
 * 4.3, T-019 AC-8).
 *
 * `manifest` is the validated `plugin.json` this version was registered
 * with (AC-35 re-validates it on every load; it is never trusted blind).
 * `unique(plugin_id, version)` is the backstop behind AC-8's refusal:
 * nothing ever overwrites an already-registered version.
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
        Schema::create('plugin_versions', function (Blueprint $table) {
            IdentifierColumn::primary($table);
            IdentifierColumn::required($table, 'plugin_id');
            $table->string('version', 20);
            $table->string('core_constraint', 40);
            $table->json('manifest');
            $table->string('package_path', 255);
            $table->char('checksum', 64);
            $table->text('signature')->nullable();
            $table->integer('migration_count')->default(0);
            $table->string('status', 30);
            $table->dateTime('released_at', 3)->nullable();
            $table->dateTime('created_at', 3);
            $table->dateTime('updated_at', 3);

            $table->unique(['plugin_id', 'version']);
            $table->foreign('plugin_id')->references('id')->on('plugins');
        });

        JsonValidity::enforce('plugin_versions', 'manifest');
    }
};
