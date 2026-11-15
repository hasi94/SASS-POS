<?php

/*
 * The security incidents the platform wants to be told about
 * (docs/database.md section 4.1).
 *
 * Distinct from `login_history`, which records every attempt: this table
 * records the few that mean something on their own - a lockout (AC-17), a
 * refresh-token replay (AC-34), and later a password reset, an MFA failure or
 * an impersonation. A row here is an event somebody may be paged about; a row
 * there is a line in a ledger.
 *
 * The three shapes are `platform_audit_logs`', for its three stated reasons:
 * an auto-incrementing BIGINT key because the table is append-only and nothing
 * offline creates a row (D-28); no foreign key on `user_id`, `tenant_id` or
 * `actor_id`, because the record outlives what it is about; and one
 * `created_at` with no `updated_at`.
 *
 * `detail` is JSON with an **explicit** validity check, because MariaDB's JSON
 * is an alias for LONGTEXT and would otherwise accept anything
 * (`JsonValidity::enforce`). It is the one column whose shape varies by `type`,
 * and nothing in T-009 writes a password or a token into it (AC-26).
 *
 * `type` is a VARCHAR and not a native enum (AC-35(b)). Both indexes are this
 * migration's own addition - section 4.1 names none - and both are the reads
 * the table exists for: "every lockout recently" is (type, created_at), and
 * "everything that happened to this user" is (user_id, created_at).
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
        Schema::create('security_events', function (Blueprint $table) {
            $table->id();
            $table->string('type', 40);
            IdentifierColumn::nullable($table, 'user_id');
            IdentifierColumn::nullable($table, 'tenant_id');
            IdentifierColumn::nullable($table, 'actor_id');
            $table->json('detail')->nullable();
            $table->binary('ip', 16)->nullable();
            $table->dateTime('created_at', 3);

            $table->index(['type', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });

        JsonValidity::enforce('security_events', 'detail');
    }
};
