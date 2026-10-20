<?php

/*
 * Every permission key the application knows (docs/database.md section 6.2).
 *
 * The key itself is the primary key, not a surrogate id: a permission is
 * identified by its name everywhere - in a role's permission list, in a
 * per-person grant, in `access_log.permission_key` - and a surrogate would
 * mean every one of those carried a number nobody can read in a log.
 *
 * **The rows are data, not schema.** Nothing is seeded here: the registry
 * sync (AC-15) writes them from what the booted providers declare, and a key
 * that disappears from the code is reported `orphaned` and never deleted
 * (AC-16), because deleting it would delete every grant of it.
 *
 * `plugin_name` is FP-213's and is deliberately absent (AC-3): this task
 * creates exactly the columns the spec's "Data this task stores" lists, so
 * that the design's wider rows cannot arrive by accident.
 *
 * `is_branch_scoped` is the attribute D-8 (c) reads: a key declared false is
 * conferred only by a null-scope contribution, so a role assigned at one
 * branch does not confer it. The registry refuses to flip it on an existing
 * key (AC-15), which is why it is worth storing rather than recomputing.
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
        Schema::create('permissions', function (Blueprint $table) {
            // VARCHAR(80), and PermissionDeclaration::MAX_KEY_LENGTH is the
            // same number in the application: a key the declaration accepts
            // and this column cannot store would be refused at the database
            // instead of at the declaration.
            $table->string('key', 80)->primary();
            $table->string('module', 40);
            $table->string('group', 40);
            $table->string('description', 255);
            $table->boolean('is_branch_scoped')->default(false);
        });
    }
};
