<?php

/*
 * The tenant database's own description of itself (docs/database.md section
 * 6.21).
 *
 * Key/value rows, and **the table is created empty**. That is D-14 and D-28, and
 * it has a consequence that must not be read as delivered: T-008 writes nothing
 * into this table at all, so AC-56's "a database with no such row proceeds" is
 * the *shipped* path and not an edge case. The writer is FP-206's provisioning
 * step, and the safety interlock section 6.21 describes - before any write,
 * assert this table's owning-tenant row equals the tenant in the request
 * context - is a later row. Nothing here enforces it, and AC-23 asserts this
 * table is empty after the baseline.
 *
 * **Two copies of the schema version, not three** (D-14). The tenant's own
 * `migrations` table is the authority for what has run in this database,
 * `tenant_databases.schema_version` in master is a cached mirror of it, and this
 * table holds no version row. `docs/database.md` section 6.21 lists one; it is
 * wrong against section 9.1, and the correction is a document row.
 *
 * The owning tenant's id lives here as a **row**, which is the only place a
 * tenant id appears inside a tenant database: it is metadata about the database,
 * not a column on business rows (AC-24). A table that needed one as a column
 * would be in the wrong database.
 *
 * @phase expand
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schema_meta', function (Blueprint $table) {
            $table->string('key', 60)->primary();
            $table->string('value', 255)->nullable();
        });
    }
};
