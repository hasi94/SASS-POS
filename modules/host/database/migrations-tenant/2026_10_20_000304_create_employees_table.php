<?php

/*
 * The people who work in this shop (docs/database.md section 6.13, trimmed by
 * D-14 to identity and access).
 *
 * **Identity and access only.** No NIC, no dates, no salary, no bank, no
 * department, no designation, no employment type, no commission, no
 * `custom_fields`, and no `branch_id` (D-9, D-14, AC-3). The brief is
 * employees, roles, permissions and PINs; payroll pulls in tables that do not
 * exist, and every one of those columns is additive later. `Refference/
 * ARCH.md` agrees: Roles and Permissions are Phase 0 and HR is Phase 4.
 *
 * **`user_id` has no foreign key and never will.** It names `users.id` in the
 * **master** database, and a foreign key cannot cross databases. It is
 * nullable - a cashier with a PIN and no web login is the normal case - and
 * unique when non-null, which is exactly what a plain unique index gives on
 * both engines: NULLs are distinct in a unique index, so two employees with
 * no web login are both accepted while two claiming the same account are not
 * (AC-7). That is the legitimate use of the nullable-unique shape, and it is
 * **not** the defect `docs/database.md` section 3.3 is about: here NULL means
 * "no constraint applies to this row", not a value. No sentinel.
 *
 * `code` is unique over a NOT NULL column, so the key covers soft-deleted
 * rows too: a code is never reused, because payroll and audit history would
 * otherwise become ambiguous (D-14, AC-7).
 *
 * **`created_by` and `updated_by` reference this same table**, declared
 * inside this `Schema::create`. A self-referencing key is legal in the expand
 * phase for the reason AC-34 states: a table the same migration created is
 * not an existing table. The first employee a shop creates has them null,
 * which is why both are nullable.
 *
 * `pin_failed_attempts` and `pin_locked_until` are not in the design's row,
 * and D-17 says why: the design lists a `pin_failed` log type and no place to
 * count, and a PIN has no lockout without a counter.
 *
 * `perm_version` starts at 1 and only ever increases (AC-52). It is an
 * unsigned integer because it is bumped by a single atomic
 * `UPDATE ... SET perm_version = perm_version + 1`, never read-then-written.
 *
 * `status` is VARCHAR(30) with the application enum beside it
 * (Flexipos\Access\Support\EmployeeStatus), not a native `enum`: adding a
 * status to a native enum is an ALTER on every tenant database.
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
        Schema::create('employees', function (Blueprint $table) {
            IdentifierColumn::primary($table);
            $table->string('code', 20);
            IdentifierColumn::nullable($table, 'user_id');
            $table->string('first_name', 100);
            $table->string('last_name', 100)->nullable();
            $table->string('display_name', 200);
            $table->string('status', 30);
            $table->string('pin_hash', 255)->nullable();
            $table->dateTime('pin_set_at', 3)->nullable();
            $table->unsignedSmallInteger('pin_failed_attempts')->default(0);
            $table->dateTime('pin_locked_until', 3)->nullable();
            $table->unsignedInteger('perm_version')->default(1);
            IdentifierColumn::nullable($table, 'created_by');
            IdentifierColumn::nullable($table, 'updated_by');
            $table->dateTime('created_at', 3);
            $table->dateTime('updated_at', 3);
            $table->dateTime('deleted_at', 3)->nullable();

            $table->unique('code');
            $table->unique('user_id');
            $table->index(['status', 'deleted_at']);
            $table->foreign('created_by')->references('id')->on('employees');
            $table->foreign('updated_by')->references('id')->on('employees');
        });
    }
};
