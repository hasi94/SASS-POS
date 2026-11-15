<?php

/*
 * The idempotency store (docs/database.md section 6.21, T-021 Ruling 6,
 * AC-18, AC-26).
 *
 * `key` is the primary key, not a ULID (`IdentifierColumn` does not apply):
 * it is the caller's own `Idempotency-Key` header value, up to 80
 * characters. `response_code` and `response_body` are nullable - a null
 * `response_code` is how "claimed, handler still running" is represented
 * (as built; the documented table does not state nullability). No foreign
 * key: the row outlives whatever actor or resource the request named, and
 * this task's store never joins it to another table.
 *
 * @phase expand
 */

use Flexipos\Kernel\Schema\Support\JsonValidity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->string('key', 80)->primary();
            $table->char('request_hash', 64);
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->json('response_body')->nullable();
            $table->dateTime('created_at', 3);

            $table->index('created_at');
        });

        JsonValidity::enforce('idempotency_keys', 'response_body');
    }
};
