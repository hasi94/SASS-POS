<?php

/*
 * A till: the device slot a branch sells from (T-014, FP-209;
 * docs/database.md section 6.3).
 *
 * `status` is a closed set validated by the application and stored as a
 * string: `pending` (created, never activated), `active`, `inactive`
 * (administratively paused) and `revoked`, which is final (D-15).
 *
 * **The activation code is stored as a hash, never as the code** (D-17).
 * `activation_code_hash` holds the SHA-256 of the eight secret characters, is
 * indexed because activation looks a terminal up by it, and is cleared when
 * the code is consumed or the terminal revoked. The design printed a plain
 * `activation_code` column; a stored plaintext credential is the thing this
 * deliberately does not do. `terminals.settings`, `invoice_prefix`,
 * `last_sync_at` and the other columns the design lists and no FP-209
 * operation writes are not created (D-10, D-24): terminal settings live in
 * `settings` with `scope_type = terminal`.
 *
 * `code` is unique per branch, not tenant-wide: two shops of one company may
 * each have a till called `T1`.
 *
 * **`current_device_id` is created here unguarded and its key belongs to the
 * contract file.** It names a `devices` row and `devices` is created two
 * files later, so a key here would reference a table that does not exist yet
 * (T-014 Ruling 2, D-32). `branch_id` and `location_id` are keyed here: both
 * tables are created by earlier files.
 *
 * No `tenant_id`, no `down()` (T-008 D-8).
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
        Schema::create('terminals', function (Blueprint $table) {
            IdentifierColumn::primary($table);
            IdentifierColumn::required($table, 'branch_id');
            IdentifierColumn::required($table, 'location_id');
            $table->string('code', 10);
            $table->string('name', 100);
            $table->string('status', 30);
            $table->char('activation_code_hash', 64)->nullable();
            $table->dateTime('activation_expires_at', 3)->nullable();
            IdentifierColumn::nullable($table, 'current_device_id');
            $table->dateTime('last_seen_at', 3)->nullable();
            $table->dateTime('created_at', 3);
            $table->dateTime('updated_at', 3);
            $table->dateTime('deleted_at', 3)->nullable();

            $table->unique(['branch_id', 'code']);
            $table->index('activation_code_hash');
            $table->foreign('branch_id')->references('id')->on('branches');
            $table->foreign('location_id')->references('id')->on('locations');
        });
    }
};
