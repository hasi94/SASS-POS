<?php

/*
 * Who did what inside this shop (docs/database.md section 6.21).
 *
 * The primary key is an auto-incrementing BIGINT, not a ULID (AC-29): this is an
 * append-only log written by the server, nothing offline creates a row, and the
 * monotonic key is also the cheapest read order. The four indexes the design
 * names are the four questions actually asked of it - what happened recently,
 * what happened to this record, what did this person do, and what did this
 * module do.
 *
 * `before`, `after` and `changed_fields` are JSON with an explicit validity
 * check: MariaDB's JSON is a LONGTEXT alias, so without the check AC-29's
 * "refuses invalid JSON" is simply false.
 *
 * `actor_name` is a **snapshot**. The employee row it came from may be renamed
 * or removed, and an audit line that changes its meaning afterwards is not an
 * audit line.
 *
 * **`branch_id` has no foreign key** (D-5): `branches` does not exist yet. The
 * four unguarded columns of this baseline are
 * `number_sequences.branch_id`, `audit_logs.branch_id` and
 * `outbox_events.branch_id`, owed to the **branches** module, and
 * `settings.updated_by`, owed to the **employees** module. Until the owing
 * module adds each key - as a *contract*-phase migration, which AC-34's static
 * rule enforces - nothing in the database stops a bad id in these columns.
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
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('actor_type', 30);
            IdentifierColumn::nullable($table, 'actor_id');
            $table->string('actor_name', 150)->nullable();
            IdentifierColumn::nullable($table, 'branch_id');
            $table->string('module', 40);
            $table->string('action', 80);
            $table->string('subject_type', 80)->nullable();
            IdentifierColumn::nullable($table, 'subject_id');
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->json('changed_fields')->nullable();
            $table->binary('ip', 16)->nullable();
            $table->string('user_agent', 255)->nullable();
            IdentifierColumn::nullable($table, 'request_id');
            $table->dateTime('created_at', 3);

            $table->index('created_at');
            $table->index(['subject_type', 'subject_id']);
            $table->index(['actor_id', 'created_at']);
            $table->index(['module', 'action', 'created_at']);
        });

        JsonValidity::enforce('audit_logs', 'before', 'after', 'changed_fields');
    }
};
