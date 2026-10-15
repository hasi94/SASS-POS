<?php

/*
 * Every login attempt and every tenant selection, successful or not
 * (docs/database.md section 4.1).
 *
 * **This is the only place the cause of a refusal survives.** D-12 gives every
 * credential failure one byte-identical 401, so the response cannot say whether
 * an email was unknown, a password wrong, an account locked or a status not
 * active. `failure_reason` is where that distinction lives, which makes this
 * table the instrument every refusal criterion of T-009 is asserted against.
 * It is also why the row is written outside any transaction and before the
 * response is rendered (AC-24).
 *
 * The three shapes follow `platform_audit_logs`, and for the same three
 * reasons stated there:
 *
 * - The primary key is an auto-incrementing BIGINT, not a ULID (D-28): it is an
 *   append-only log written by the platform itself, nothing offline creates a
 *   row, and the monotonic key is also the cheapest read order. The identifier
 *   rule governs references to another row's identity - which `user_id` and
 *   `tenant_id` are, so those two do take the identifier column.
 * - `user_id` and `tenant_id` carry **no foreign key**. The log outlives what
 *   it is about, and a key would either block a deletion the design permits or
 *   cascade away the evidence. `user_id` is also null by design when the
 *   submitted email matched nobody (AC-23).
 * - There is one `created_at` and no `updated_at`: a row that could be updated
 *   would not be a history.
 *
 * `email` is stored trimmed, as submitted, and in its display form - not
 * lowercased. The lookup key is `users.email_lower`; this column is evidence of
 * what was typed. The (email, created_at) index is what makes "every attempt
 * against this address" answerable, and (user_id, created_at) the same question
 * for a known user. Retention is 180 days by design and pruning is not
 * implemented here (D-17).
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
        Schema::create('login_history', function (Blueprint $table) {
            $table->id();
            IdentifierColumn::nullable($table, 'user_id');
            IdentifierColumn::nullable($table, 'tenant_id');
            $table->string('email', 191);
            $table->string('principal_type', 30);
            $table->boolean('success');
            $table->string('failure_reason', 40)->nullable();
            $table->binary('ip', 16)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->string('device_id', 100)->nullable();
            $table->dateTime('created_at', 3);

            $table->index(['email', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }
};
