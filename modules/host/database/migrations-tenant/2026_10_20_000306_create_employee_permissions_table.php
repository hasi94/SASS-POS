<?php

/*
 * Per-person grants and explicit denials, on top of roles (docs/database.md
 * section 6.2).
 *
 * **This table does not have the primary key the design prints, and the
 * reason is that the printed key cannot store the row it exists for**
 * (D-10, Ruling 4). The design prints PK (employee_id, permission_key,
 * branch_id) with `branch_id` NULL meaning "all branches" - but MySQL and
 * MariaDB make every primary-key column NOT NULL, so on the real engine that
 * row is unrepresentable. SQLite is worse than wrong here: it is *silently*
 * wrong. MEASURED in step 2 - a non-integer composite primary key on the
 * stand-in accepted a NULL branch, and then accepted a SECOND one. A test
 * written against the printed design would have passed on the stand-in and
 * failed on the engine.
 *
 * So: a surrogate ULID primary key, and the uniqueness the design meant is
 * carried by a unique index over the stored null-safe sentinel, exactly as
 * on `employee_roles`. This is the same FP-180 defect in its stronger form,
 * and `employee_permissions` is **not** on FP-180's list of eleven.
 *
 * `allowed` is the whole point of the table: a row with `allowed = false` is
 * an explicit deny, and deny wins over every allowing contribution at any
 * scope covering the branch asked about (D-8 (a)). There is no "more
 * specific allow beats a general deny".
 *
 * **`branch_id` is unguarded and FP-209 owes its foreign key** (D-1, D-30):
 * CHAR(26), nullable, no `branches` table to reference, and nothing in the
 * database stops a bad branch id here until the branches module adds the key
 * as a contract-phase migration.
 *
 * A row naming a branch for a key whose `is_branch_scoped` is false is
 * refused at **write** with `scope_not_applicable` (D-8 (e)). That is an
 * application rule: it reads `permissions.is_branch_scoped`, which no
 * constraint in this table can see.
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
        Schema::create('employee_permissions', function (Blueprint $table) {
            IdentifierColumn::primary($table);
            IdentifierColumn::required($table, 'employee_id');
            $table->string('permission_key', 80);
            IdentifierColumn::nullable($table, 'branch_id');
            IdentifierColumn::sentinel($table, 'branch_id', 'branch_id_key');
            $table->boolean('allowed');

            $table->unique(['employee_id', 'permission_key', 'branch_id_key']);
            $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();
            $table->foreign('permission_key')->references('key')->on('permissions');
        });
    }
};
