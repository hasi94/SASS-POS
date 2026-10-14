<?php

/*
 * Who changed a tenant, a mapping or a credential (docs/database.md section
 * 4.9).
 *
 * **Never pruned below seven years**: this is the record of who moved a tenant's
 * database. Three shapes follow from that.
 *
 * - The primary key is an auto-incrementing BIGINT, not a ULID: it is an
 *   append-only log written by the platform itself, nothing offline creates a
 *   row, and the monotonic key is also the cheapest read order (AC-10).
 * - `tenant_id` carries **no foreign key**. The log outlives the tenant it is
 *   about; a key would either block a deletion the design permits or cascade
 *   away the evidence.
 * - `before` and `after` are JSON with an explicit validity check, because
 *   MariaDB's JSON is an alias for LONGTEXT and would otherwise accept anything
 *   (AC-10).
 *
 * There is one `created_at` and no `updated_at`: a row that could be updated
 * would not be an audit log.
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
        Schema::create('platform_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('actor_type', 30);
            IdentifierColumn::nullable($table, 'actor_id');
            $table->string('actor_email', 191)->nullable();
            IdentifierColumn::nullable($table, 'tenant_id');
            $table->string('action', 80);
            $table->string('subject_type', 80)->nullable();
            IdentifierColumn::nullable($table, 'subject_id');
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->binary('ip', 16)->nullable();
            $table->string('user_agent', 255)->nullable();
            IdentifierColumn::nullable($table, 'request_id');
            $table->dateTime('created_at', 3);

            $table->index(['tenant_id', 'created_at']);
            $table->index(['action', 'created_at']);
        });

        JsonValidity::enforce('platform_audit_logs', 'before', 'after');
    }
};
