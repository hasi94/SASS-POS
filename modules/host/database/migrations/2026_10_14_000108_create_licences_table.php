<?php

/*
 * Platform, module and plugin licences (docs/database.md section 4.3).
 *
 * `licence_key` is CHAR(64) and unique - AC-9's duplicate refusal rides that
 * key. `signature` is a detached signature, so an offline installation can
 * verify a licence without reaching the platform; that is the whole reason it is
 * a stored column and not something derived at read time.
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
        Schema::create('licences', function (Blueprint $table) {
            IdentifierColumn::primary($table);
            IdentifierColumn::required($table, 'tenant_id');
            $table->string('type', 30);
            $table->string('subject', 100);
            $table->char('licence_key', 64)->unique();
            $table->integer('seats')->nullable();
            $table->integer('terminals')->nullable();
            $table->dateTime('valid_from', 3)->nullable();
            $table->dateTime('valid_until', 3)->nullable();
            $table->string('status', 30);
            IdentifierColumn::nullable($table, 'issued_by');
            $table->text('signature')->nullable();
            $table->dateTime('revoked_at', 3)->nullable();
            $table->string('revoked_reason', 255)->nullable();
            $table->dateTime('created_at', 3);
            $table->dateTime('updated_at', 3);

            $table->index(['tenant_id', 'status']);
            $table->index('valid_until');
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('issued_by')->references('id')->on('users');
        });
    }
};
