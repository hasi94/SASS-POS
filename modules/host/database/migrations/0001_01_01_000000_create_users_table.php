<?php

/*
 * The master's login identity, and the two stock tables that stay beside it.
 *
 * T-008 step 3, AC-2 and D-18. `users` is no longer Laravel's example table: it
 * is `docs/database.md` section 4.1's, with a ULID primary key, an Argon2id
 * `password_hash` and the lockout columns authentication (FP-207) needs. The
 * stock `password` and `remember_token` columns are gone - this host issues
 * tokens, it does not keep a cookie hash - and so is the integer id.
 *
 * **One row per human who logs in**, tenant customer and platform staff alike,
 * so there is one login endpoint, one token issuer and one MFA path. Which
 * tenants a user may open is `tenant_users`; `is_platform_staff` and
 * `platform_role` are the platform's own side of the same person.
 *
 * `password_reset_tokens` and `sessions` are **unchanged** (D-18). They are two
 * of AC-1's eight Laravel system tables, they are the only tables in either
 * schema allowed a `timestamp` column (AC-35(c)), and `sessions` stays because
 * the shipped `.env.example` names the database session driver and a test pins
 * it. `sessions.user_id` keeps its stock integer type and no foreign key: it is
 * written by the framework's session handler, which has never had one.
 *
 * This file keeps the `down()` it shipped with, which is the one place in either
 * tree that has one (D-8): it is stock scaffolding, not a schema change of ours,
 * and AC-33 reads `up()`.
 *
 * @phase expand
 */

use Flexipos\Kernel\Schema\Support\IdentifierColumn;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            IdentifierColumn::primary($table);
            $table->string('name', 150);
            // Globally unique, and case-insensitively so: the collation comes
            // from the connection (utf8mb4_unicode_ci), which is what makes
            // AC-8(a) true in the database rather than in a service.
            $table->string('email', 191)->unique();
            $table->dateTime('email_verified_at', 3)->nullable();
            $table->string('phone', 30)->nullable();
            // Argon2id. The column holds a hash and the name says so, which is
            // what keeps AC-6's credential-column scan honest about the one
            // password-shaped name the master is allowed to have.
            $table->string('password_hash', 255);
            $table->string('status', 30);
            $table->boolean('is_platform_staff')->default(false);
            $table->string('platform_role', 30)->nullable();
            $table->string('locale', 10)->nullable();
            $table->string('timezone', 64)->nullable();
            $table->string('avatar_path', 255)->nullable();
            $table->unsignedSmallInteger('failed_attempts')->default(0);
            $table->dateTime('locked_until', 3)->nullable();
            $table->boolean('must_change_password')->default(false);
            $table->dateTime('password_changed_at', 3)->nullable();
            $table->dateTime('last_login_at', 3)->nullable();
            // VARBINARY(16): one column holds both an IPv4 and an IPv6 address.
            $table->binary('last_login_ip', 16)->nullable();
            $table->dateTime('created_at', 3);
            $table->dateTime('updated_at', 3);
            $table->dateTime('deleted_at', 3)->nullable();

            $table->index('status');
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
