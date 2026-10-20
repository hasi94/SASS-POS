<?php

/*
 * Which permissions a role confers (docs/database.md section 6.2).
 *
 * The primary key is the pair, so a role cannot hold a key twice and
 * "replace, not merge" (AC-20) has something to be true of. No surrogate id
 * and no timestamps: a row here is a membership, it is never referenced by
 * anything else, and `roles.manage` rewrites the whole set in one
 * transaction.
 *
 * `role_id` cascades on delete and `permission_key` does not. The asymmetry
 * is the one the design prints and it is right: deleting a role should take
 * its memberships with it, while a permission key is never deleted at all -
 * a key that disappears from the code is reported `orphaned` and left alone
 * (AC-16), precisely so that the grants of it survive a module being
 * switched off.
 *
 * Both foreign keys are declared inside this `Schema::create`, which the
 * expand phase permits: a table the same migration created is not an
 * existing table, and AC-34's static rule says so in those terms.
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
        Schema::create('role_permissions', function (Blueprint $table) {
            IdentifierColumn::required($table, 'role_id');
            $table->string('permission_key', 80);

            $table->primary(['role_id', 'permission_key']);
            $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
            $table->foreign('permission_key')->references('key')->on('permissions');
        });
    }
};
