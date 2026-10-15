<?php

/*
 * One refresh token per row, stored as a hash, rotated inside a family
 * (docs/database.md section 4.1).
 *
 * **The primary key is a ULID identifier column, not a BIGINT.** This is the
 * opposite case to `login_history` and `platform_audit_logs` (D-28): a refresh
 * row is a row other things refer to - its family, its successor after a
 * rotation - and its id is minted alongside a secret, so it must not be
 * guessable from the one before it.
 *
 * **The token itself is never here.** `token_hash` holds the SHA-256 hex of the
 * value handed to the client and nothing else, which is why it is a fixed
 * `CHAR(64)`: a wider column would accept something that is not a hash. The
 * unique key on it is AC-32's, and it is also the thing that makes a replayed
 * token findable rather than merely refused.
 *
 * `family_id` is the rotation chain. It is indexed because a replay revokes the
 * **whole** family in one statement (AC-34), so the family is a read key and
 * not only a label. That index is this migration's own addition; section 4.1
 * names only the (principal_type, principal_id) index, which is the lookup the
 * refresh path uses.
 *
 * `tenant_id` is nullable and carries a foreign key: a platform token has no
 * tenant, and a tenant-scoped one must name a tenant that exists. It is the one
 * key on this table - the token row does not outlive its tenant the way an
 * audit log does, because revoking a tenant's sessions is exactly what should
 * happen when the tenant goes.
 *
 * `principal_type` is `user`, `terminal` or `service` as a VARCHAR, not a
 * native enum (AC-35(b)); only `user` is written by T-009, and FP-209 adds the
 * second. `principal_id` is therefore a reference whose target table depends on
 * the type, which is why it takes no foreign key.
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
        Schema::create('refresh_tokens', function (Blueprint $table) {
            IdentifierColumn::primary($table);
            $table->string('principal_type', 30);
            IdentifierColumn::required($table, 'principal_id');
            IdentifierColumn::nullable($table, 'tenant_id');
            $table->char('token_hash', 64)->unique();
            IdentifierColumn::required($table, 'family_id');
            $table->dateTime('expires_at', 3);
            $table->dateTime('used_at', 3)->nullable();
            $table->dateTime('revoked_at', 3)->nullable();
            $table->binary('ip', 16)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->dateTime('created_at', 3);
            $table->dateTime('updated_at', 3);

            $table->index(['principal_type', 'principal_id']);
            $table->index('family_id');
            $table->foreign('tenant_id')->references('id')->on('tenants');
        });
    }
};
