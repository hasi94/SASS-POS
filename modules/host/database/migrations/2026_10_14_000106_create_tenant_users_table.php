<?php

/*
 * Which humans may open which tenant (docs/database.md section 4.1).
 *
 * `employee_id` is the one deliberate cross-database reference in the whole
 * design: it names a row in `employees` **inside the tenant database**, where no
 * foreign key can reach. The column is CHAR(26) and nullable, and nothing in the
 * database stops a bad id there - a nightly consistency check does, and that
 * check is a later row.
 *
 * The unique (tenant_id, employee_id) key is the design's **mirror** case
 * (section 3.3): here the NULL rows are *meant* to escape the key, because many
 * users of one tenant may have no employee record at all, and both engines treat
 * NULLs as distinct in a unique index. So this key gets no sentinel column, and
 * AC-8(c) asserts exactly that - a duplicate non-null pair is refused while two
 * rows with the same tenant and a NULL employee_id are accepted. It is the same
 * engine behaviour that makes `settings` and `number_sequences` wrong without a
 * sentinel; what differs is whether NULL means "none" or "all".
 *
 * `access_level` is coarse on purpose - it is read before the tenant database is
 * open, and real permissions live inside it.
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
        Schema::create('tenant_users', function (Blueprint $table) {
            IdentifierColumn::primary($table);
            IdentifierColumn::required($table, 'tenant_id');
            IdentifierColumn::required($table, 'user_id');
            IdentifierColumn::nullable($table, 'employee_id');
            $table->string('access_level', 30);
            $table->string('status', 30);
            $table->boolean('is_default')->default(false);
            IdentifierColumn::nullable($table, 'invited_by');
            $table->dateTime('invited_at', 3)->nullable();
            $table->dateTime('accepted_at', 3)->nullable();
            $table->dateTime('last_accessed_at', 3)->nullable();
            $table->dateTime('created_at', 3);
            $table->dateTime('updated_at', 3);
            $table->dateTime('deleted_at', 3)->nullable();

            $table->unique(['tenant_id', 'user_id']);
            $table->unique(['tenant_id', 'employee_id']);
            $table->index(['user_id', 'status']);
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('user_id')->references('id')->on('users');
            $table->foreign('invited_by')->references('id')->on('users');
        });
    }
};
