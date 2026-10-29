<?php

/*
 * Where an aged `audit_logs` row lives once the daily archival job has moved
 * it (T-017 D-10, Ruling 5, AC-7).
 *
 * **One permanent table, not the year-suffixed one `docs/database.md`
 * section 11 literally describes.** Creating a table at runtime, per year,
 * would break the standing rule that every schema change runs through the
 * per-tenant migration runner (CLAUDE.md; docs/patterns.md section 4.6): a
 * scheduled job cannot run DDL against every shop database safely or
 * reviewably. D-10 records this as the document line to correct, not the
 * thing to build literally - corrected in this same step, in
 * docs/database.md sections 6.21 and 11.
 *
 * **Column-for-column identical to `audit_logs`**, with the same four
 * indexes: `Flexipos\Access\Support\AuditColumns::ALL` is the one list both
 * tables are built from, and a shape test pins it against both live tables
 * so a later expand step that adds a column to one and not the other goes
 * red.
 *
 * **`branch_id` stays unguarded**, for the identical reason it is unguarded
 * on `audit_logs` (T-014 D-2, FP-313): the branches/organization module owns
 * that foreign key, unchanged by this task.
 *
 * **No `down()`.** A reversal that dropped this table would be a reversal
 * that could destroy archived audit history, which is exactly the kind of
 * loss this table exists to prevent.
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
        Schema::create('audit_logs_archive', function (Blueprint $table) {
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

        JsonValidity::enforce('audit_logs_archive', 'before', 'after', 'changed_fields');
    }
};
