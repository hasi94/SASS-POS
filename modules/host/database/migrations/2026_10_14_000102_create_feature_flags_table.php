<?php

/*
 * The feature-flag registry (docs/database.md section 4.2).
 *
 * **Created before `plan_features` and `tenant_feature_flags`**, which both
 * carry a foreign key onto this table's key - the design's arrow order names
 * `plans` first, but a key cannot reference a table that does not exist yet.
 * That key is what AC-9's "refuses a feature or flag key that is not in
 * feature_flags" is made of.
 *
 * The key is the primary key: a flag is named by modules and plugins at boot,
 * and a surrogate id would let the same name be declared twice.
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
        Schema::create('feature_flags', function (Blueprint $table) {
            $table->string('key', 60)->primary();
            $table->string('description', 255)->nullable();
            $table->boolean('default_enabled')->default(false);
            $table->string('owner_module', 40);
            $table->boolean('is_plugin_flag')->default(false);
        });
    }
};
