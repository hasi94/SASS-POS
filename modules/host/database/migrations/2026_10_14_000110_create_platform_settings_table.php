<?php

/*
 * The platform's own settings (docs/database.md section 4.9).
 *
 * The key is the primary key, the value is JSON with an explicit validity
 * check, and `is_secret` marks a value the platform UI must not print. A secret
 * *value* still does not belong here: AC-6 allows no column that can hold a
 * credential, so a setting that needs one holds a reference and the resolver
 * fetches it.
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
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->string('key', 120)->primary();
            $table->json('value')->nullable();
            $table->string('type', 30);
            $table->string('description', 255)->nullable();
            $table->boolean('is_secret')->default(false);
            IdentifierColumn::nullable($table, 'updated_by');
            $table->dateTime('updated_at', 3)->nullable();

            $table->foreign('updated_by')->references('id')->on('users');
        });

        JsonValidity::enforce('platform_settings', 'value');
    }
};
