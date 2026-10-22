<?php

/*
 * The hardware a terminal was activated from (T-014, FP-209;
 * docs/database.md section 6.4).
 *
 * **At most one non-revoked device per terminal, and in T-014 exactly one
 * ever** (D-16): the one it was activated from. The table keeps the history
 * shape of the design, so a later task that lets a till change tablets adds a
 * row rather than a column. A device is revoked only as part of revoking its
 * terminal, and no route modifies or deletes one.
 *
 * `fingerprint` is exactly 64 lower-case hex characters, stored as submitted:
 * lower-case only is what lets a plain equality comparison decide that one
 * fingerprint is one device (T-014 Ruling 6). It is indexed because
 * activation asks whether it is already another terminal's live device. The
 * four descriptive columns are nullable and length-capped; an over-length
 * value is refused at activation and never truncated.
 *
 * `terminal_id` and `revoked_by` are keyed here: `terminals` and `employees`
 * are created by earlier files. The key from `terminals.current_device_id`
 * back to this table is the contract file's (T-014 D-32).
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
        Schema::create('devices', function (Blueprint $table) {
            IdentifierColumn::primary($table);
            IdentifierColumn::required($table, 'terminal_id');
            $table->char('fingerprint', 64);
            $table->string('platform', 50)->nullable();
            $table->string('browser', 100)->nullable();
            $table->string('os', 100)->nullable();
            $table->string('screen', 30)->nullable();
            $table->dateTime('registered_at', 3);
            $table->dateTime('last_seen_at', 3)->nullable();
            $table->dateTime('revoked_at', 3)->nullable();
            IdentifierColumn::nullable($table, 'revoked_by');
            $table->dateTime('created_at', 3);
            $table->dateTime('updated_at', 3);

            $table->index('fingerprint');
            $table->index(['terminal_id', 'registered_at']);
            $table->foreign('terminal_id')->references('id')->on('terminals');
            $table->foreign('revoked_by')->references('id')->on('employees');
        });
    }
};
