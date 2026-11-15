<?php

/*
 * One version's declared dependency on another plugin (docs/database.md
 * section 4.3, T-019 AC-9).
 *
 * `depends_on_plugin` is a name, not a foreign key: the dependency may name
 * a plugin that is not registered yet, and AC-9's refusal is checked at
 * install/enable time against what the tenant actually has installed, not
 * against a key that would force registration order.
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
        Schema::create('plugin_dependencies', function (Blueprint $table) {
            IdentifierColumn::required($table, 'plugin_version_id');
            $table->string('depends_on_plugin', 100);
            $table->string('version_constraint', 40);

            $table->primary(['plugin_version_id', 'depends_on_plugin']);
            $table->foreign('plugin_version_id')->references('id')->on('plugin_versions');
        });
    }
};
