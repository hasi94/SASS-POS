<?php

/*
 * AC-24's control, source half: a tenant migration that declares a `tenant_id`
 * column.
 *
 * Nothing in a tenant database carries one: the database *is* the tenant, so a
 * table that needs to know which tenant it belongs to is in the wrong database.
 * The owning tenant's id is a `schema_meta` row, which is metadata about the
 * database rather than a column on business rows.
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
        Schema::create('fixture_scoped_rows', function (Blueprint $table) {
            IdentifierColumn::primary($table);
            IdentifierColumn::required($table, 'tenant_id');
        });
    }
};
