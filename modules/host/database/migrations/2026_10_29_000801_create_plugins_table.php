<?php

/*
 * The master's plugin catalogue (docs/database.md section 4.3, T-019 AC-7).
 *
 * `latest_version_id` references a row in `plugin_versions`, a table this
 * migration runs before - the design names no foreign key for it, so it
 * stays unguarded (plan section 4, -> ba informational).
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
        Schema::create('plugins', function (Blueprint $table) {
            IdentifierColumn::primary($table);
            $table->string('name', 100)->unique();
            $table->string('display_name', 150)->nullable();
            $table->string('type', 30);
            $table->string('author', 150)->nullable();
            $table->text('description')->nullable();
            $table->string('visibility', 30);
            $table->string('status', 30);
            IdentifierColumn::nullable($table, 'latest_version_id');
            $table->string('signature_key_id', 40)->nullable();
            $table->boolean('requires_licence')->default(false);
            $table->dateTime('created_at', 3);
            $table->dateTime('updated_at', 3);
            $table->dateTime('deleted_at', 3)->nullable();

            $table->index('status');
        });
    }
};
