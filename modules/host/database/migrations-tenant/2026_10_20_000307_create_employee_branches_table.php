<?php

/*
 * Which branches an employee is attached to, and which one is their default
 * (docs/database.md section 6.2).
 *
 * **The one-default rule is a stored generated column, and it is NOT section
 * 3.3's sentinel.** A unique index on (employee_id, is_default) would also
 * refuse a *second non-default* row, which is the normal case; a partial
 * index exists on neither MySQL nor MariaDB - that is the exact shape T-006
 * removed from `barcode_rules`. So `default_employee_id` is
 * `CASE WHEN is_default THEN employee_id END`, stored, with a plain unique
 * index over it: it holds the employee id on the default row and NULL on
 * every other, and NULLs being distinct in a unique index is precisely what
 * makes the non-default rows unconstrained.
 *
 * **This is the inverse of section 3.3 and must not be filed as an instance
 * of it.** There, NULL is a meaningful value and a plain nullable unique key
 * is a defect. Here, NULL means "no constraint applies to this row", which is
 * the one case a nullable unique key is correct for. It is deliberately not
 * routed through `IdentifierColumn::sentinel()`, whose docblock says in terms
 * that the next person needing a sentinel on a different shape writes their
 * own and says why. Recorded here so FP-180 does not later collect it.
 *
 * One consequence of declaring it outside the helper, stated rather than
 * discovered: `default_employee_id` is the one ULID-shaped column in this
 * tree that does not carry `ascii` / `ascii_bin`, because
 * `IdentifierColumn` is the only site allowed to name a charset and a
 * migration may not name one itself. It holds a copy of `employee_id` and
 * nothing compares it to anything but itself, so the weaker collation cannot
 * make two distinct employees collide: a ULID is upper-case Crockford base32
 * and two of them never differ only in case.
 *
 * MEASURED on the stand-in in step 2: the first default is accepted, two
 * further non-defaults are accepted, a SECOND default for the same employee
 * is refused, another employee's default is accepted, and a
 * `where default_employee_id = ?` lookup returns that employee's default
 * branch.
 *
 * **`branch_id` is unguarded and FP-209 owes its foreign key** (D-1, D-30):
 * CHAR(26), no `branches` table to reference, and nothing in the database
 * stops a bad branch id here until the branches module adds the key as a
 * contract-phase migration. Unlike the other two unguarded branch columns
 * this one is NOT NULL, because a row here *is* an attachment to one branch.
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
        Schema::create('employee_branches', function (Blueprint $table) {
            IdentifierColumn::required($table, 'employee_id');
            IdentifierColumn::required($table, 'branch_id');
            $table->boolean('is_default')->default(false);
            $table->ulid('default_employee_id')
                ->nullable()
                ->storedAs('CASE WHEN is_default THEN employee_id END');
            $table->dateTime('created_at', 3);
            $table->dateTime('updated_at', 3);

            $table->primary(['employee_id', 'branch_id']);
            $table->unique('default_employee_id');
            $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();
        });
    }
};
