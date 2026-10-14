<?php

/*
 * The MySQL/MariaDB servers tenant databases live on (docs/database.md section
 * 4.4).
 *
 * This table and the next one are the heart of the architecture: everything else
 * can be rebuilt, and a wrong or lost mapping row means a tenant's data is
 * unreachable.
 *
 * `host` and `port` are here rather than in configuration because that is what
 * lets tenants spread across servers without a deployment change, and
 * `max_tenants` is the capacity cap placement arithmetic reads (section 10).
 *
 * **No password, anywhere.** `provisioner_credential_ref` is a reference a
 * `SecretResolver` dereferences at connect time (`file://`, `vault://`, `env://`
 * and the rest); the `_ref` suffix is what AC-6's credential-column scan reads.
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
        Schema::create('database_servers', function (Blueprint $table) {
            IdentifierColumn::primary($table);
            $table->string('code', 30)->unique();
            $table->string('name', 150);
            $table->string('host', 191);
            $table->unsignedSmallInteger('port')->default(3306);
            $table->string('read_host', 191)->nullable();
            $table->unsignedSmallInteger('read_port')->nullable();
            $table->string('engine', 30);
            $table->string('engine_version', 30)->nullable();
            $table->string('tls_mode', 30);
            $table->string('tls_ca_ref', 255)->nullable();
            $table->string('provisioner_credential_ref', 255);
            $table->string('region', 20)->nullable();
            $table->string('tier', 30);
            $table->unsignedSmallInteger('max_tenants');
            $table->unsignedSmallInteger('tenant_count')->default(0);
            $table->unsignedSmallInteger('max_connections')->nullable();
            $table->string('status', 30);
            $table->boolean('accepts_new_tenants')->default(true);
            $table->text('notes')->nullable();
            $table->dateTime('created_at', 3);
            $table->dateTime('updated_at', 3);

            $table->index('status');
        });
    }
};
