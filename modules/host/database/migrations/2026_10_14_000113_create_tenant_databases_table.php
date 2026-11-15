<?php

/*
 * The mapping from a tenant to the database that holds its data
 * (docs/database.md section 4.4).
 *
 * **One row per tenant, and the tenant id is the primary key** (AC-5). Not a
 * surrogate id with a unique index beside it: a second mapping row for one
 * tenant is not a duplicate to be cleaned up later, it is two answers to "where
 * is this shop's data", and the key makes it unrepresentable.
 *
 * The keys are what the serving path and the runner ride on:
 *  - unique `database_identifier` - the TEN000123 that mirrors `tenants.code`;
 *  - unique (`server_id`, `database_name`) - two servers may both hold a
 *    database called the same thing, one server may not (AC-5's control);
 *  - index (`schema_version`) - "who is behind" is a question asked of every
 *    tenant at once (AC-57);
 *  - index (`status`) and (`server_id`, `status`) - the runner selects by both.
 *
 * **Never a password, and not even a ciphertext** (AC-6, AC-18): the row holds
 * `app_credential_ref` and `migrate_credential_ref`, which a `SecretResolver`
 * dereferences at connect time, plus `credential_version` so a rotation
 * invalidates a cached mapping by construction. The serving path never sees the
 * migrate pair - that is the runner's own mapping type (AC-18) - and nothing
 * cached ever holds the resolved secret (AC-15).
 *
 * `schema_version` is a **mirror** of the tenant's own `migrations` table, which
 * is the authority (D-14). Two copies, not three: `schema_meta` holds no version
 * row. `charset` and `collation` are recorded here because a restored or moved
 * database has to be rebuilt the way it was; they are data about a database, not
 * a charset this migration names (AC-35(d)).
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
        Schema::create('tenant_databases', function (Blueprint $table) {
            IdentifierColumn::primary($table, 'tenant_id');
            IdentifierColumn::required($table, 'server_id');
            IdentifierColumn::nullable($table, 'read_server_id');
            $table->string('database_identifier', 30)->unique();
            $table->string('database_name', 64);
            $table->string('app_username', 32);
            $table->string('app_credential_ref', 255);
            $table->string('migrate_username', 32);
            $table->string('migrate_credential_ref', 255);
            $table->integer('credential_version')->default(1);
            $table->dateTime('credential_rotated_at', 3)->nullable();
            $table->string('charset', 30);
            $table->string('collation', 64);
            $table->string('status', 30);
            $table->string('status_reason', 255)->nullable();
            $table->bigInteger('schema_version')->default(0);
            $table->bigInteger('target_schema_version')->nullable();
            $table->dateTime('last_migrated_at', 3)->nullable();
            $table->text('last_migration_error')->nullable();
            $table->string('profile', 30);
            $table->bigInteger('size_bytes')->nullable();
            $table->unsignedSmallInteger('table_count')->nullable();
            $table->bigInteger('row_estimate')->nullable();
            $table->dateTime('measured_at', 3)->nullable();
            $table->dateTime('provisioned_at', 3)->nullable();
            $table->dateTime('last_opened_at', 3)->nullable();
            $table->dateTime('archived_at', 3)->nullable();
            $table->dateTime('created_at', 3);
            $table->dateTime('updated_at', 3);

            $table->unique(['server_id', 'database_name']);
            $table->index('schema_version');
            $table->index('status');
            $table->index(['server_id', 'status']);
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('server_id')->references('id')->on('database_servers');
            $table->foreign('read_server_id')->references('id')->on('database_servers');
        });
    }
};
