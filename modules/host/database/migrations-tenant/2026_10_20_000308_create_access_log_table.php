<?php

/*
 * What happened at the till and at the permission guard (docs/database.md
 * section 6.2).
 *
 * Distinct from the master's `login_history`, which records web logins. This
 * one is tenant-local and append-only, so the primary key is an
 * auto-incrementing BIGINT rather than a ULID, for the reason `audit_logs`
 * gives: nothing offline creates a row and the monotonic key is also the
 * cheapest read order.
 *
 * **This is the only place a refusal's finer cause survives** (D-26). A
 * caller is told `not_permitted`; whether the cause was `not_granted`, an
 * `explicit_deny` or `out_of_scope` is written to `detail.reason` here. That
 * is the same role `login_history.failure_reason` plays on the master side,
 * and it is what lets a criterion assert the cause rather than the status
 * class.
 *
 * **`permission_key` deliberately has no foreign key**, although
 * `permissions.key` exists in this same tree. The row this table most needs
 * to be able to write is the one naming a key that is *not* registered -
 * `unknown_permission` is a server defect and must be logged, not lost - and
 * a foreign key would refuse exactly that row. The same argument covers a
 * key logged before a module is switched off.
 *
 * `terminal_id` and `branch_id` are unguarded for the ordinary reason: no
 * `terminals` table and no `branches` table exist (FP-209 owes the branch
 * key). Nothing in the database stops a bad id in either column.
 *
 * `detail` is JSON with an explicit validity check, because MariaDB's JSON is
 * a LONGTEXT alias and without the check the column holds anything.
 *
 * `ip` is a packed VARBINARY(16): `inet_pton` output, both families, which
 * round-trips through this column on both engines. It is nullable, because a
 * PIN verification performed in-process has no request behind it.
 *
 * This task writes only three of the design's five types - `pin_login`,
 * `pin_failed` and `permission_denied` - and the single writer refuses any
 * other at construction (D-21). `terminal_login` and `override_used` belong
 * to tasks that do not exist yet; the column is wide enough for them.
 *
 * @phase expand
 */

use Flexipos\Kernel\Schema\Support\IdentifierColumn;
use Flexipos\Kernel\Schema\Support\JsonValidity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_log', function (Blueprint $table) {
            $table->id();
            IdentifierColumn::nullable($table, 'employee_id');
            IdentifierColumn::nullable($table, 'terminal_id');
            $table->string('type', 30);
            $table->string('permission_key', 80)->nullable();
            IdentifierColumn::nullable($table, 'branch_id');
            $table->json('detail')->nullable();
            $table->binary('ip', 16)->nullable();
            $table->dateTime('created_at', 3);

            $table->index(['employee_id', 'created_at']);
            $table->foreign('employee_id')->references('id')->on('employees');
        });

        JsonValidity::enforce('access_log', 'detail');
    }
};
