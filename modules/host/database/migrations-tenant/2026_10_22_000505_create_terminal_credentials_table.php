<?php

/*
 * The tenant-side half of a terminal's credential (T-014, FP-209;
 * docs/database.md section 6.5).
 *
 * **One row per terminal, so the terminal id is the primary key** and a second
 * credential for a terminal is refused by the key itself. The credential a
 * till presents is its master refresh-token family (principal type
 * `terminal`); this row is the tenant database's own say, consulted on every
 * terminal request, so that revoking a terminal takes effect on the next
 * request and not when a 15-minute token expires (D-19, D-20). The design's
 * `secret_hash` and `rotated_at` are not created: a second secret with no
 * route that checks it would be an unchecked credential.
 *
 * `status` is `active` or `revoked`. **No `created_at` or `updated_at`**: the
 * spec lists none, `activated_at` and `revoked_at` carry the two instants that
 * matter, and a column nothing writes is a promise nobody keeps (D-10).
 * `device_fingerprint` repeats the fingerprint of the device the credential
 * was activated from.
 *
 * `activated_by` names the employee who issued the activation code in force
 * and is keyed here. **`revoked_by` is created here and keyed by the contract
 * file**, like the employee-audit columns of `locations` (T-014 Ruling 10 (e),
 * D-45); between the two runs it is unguarded, which is harmless for the
 * reason the branches file gives.
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
        Schema::create('terminal_credentials', function (Blueprint $table) {
            IdentifierColumn::primary($table, 'terminal_id');
            $table->char('device_fingerprint', 64)->nullable();
            $table->string('status', 30);
            $table->dateTime('activated_at', 3);
            IdentifierColumn::nullable($table, 'activated_by');
            $table->dateTime('revoked_at', 3)->nullable();
            IdentifierColumn::nullable($table, 'revoked_by');

            $table->foreign('terminal_id')->references('id')->on('terminals');
            $table->foreign('activated_by')->references('id')->on('employees');
        });
    }
};
