<?php

/*
 * The catalogue of tenant migrations (docs/database.md section 4.5).
 *
 * One row per file in the tenant tree, published by `schema:publish` and
 * committed as a fixture beside it, so the catalogue in a database can be
 * compared with the catalogue in the branch (AC-31, AC-32).
 *
 * `version` is the primary key and is **not** auto-incrementing: it is a
 * monotonic ordinal assigned in migration order, and the number has to be the
 * same on every installation for `tenant_databases.schema_version` to mean
 * anything. `checksum` is of the file's content, LF-normalised, which is what
 * detects a released migration being rewritten. `phase` is expand, backfill or
 * contract - VARCHAR(30) with an application enum, like every other
 * enumeration here.
 *
 * This table is empty after the baseline: publishing is step 5's command, not a
 * migration.
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
        Schema::create('schema_versions', function (Blueprint $table) {
            $table->bigInteger('version')->primary();
            $table->string('migration', 191)->unique();
            $table->string('release', 20)->nullable();
            $table->string('phase', 30);
            $table->char('checksum', 64);
            $table->boolean('is_destructive')->default(false);
            $table->boolean('requires_offline')->default(false);
            $table->integer('estimated_seconds')->nullable();
            $table->text('notes')->nullable();
            $table->dateTime('released_at', 3)->nullable();
        });
    }
};
