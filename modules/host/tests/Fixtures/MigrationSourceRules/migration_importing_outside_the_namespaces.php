<?php

/*
 * Check (d)'s positive control: a migration importing a symbol from outside
 * the two namespaces a migration may reach for.
 *
 * This is the hole check (c) leaves on its own. (c) scans one directory -
 * packages/kernel/src/Schema/Support - so a helper reached for anywhere else
 * is a statement no rule in this task can read, whatever that helper does. The
 * *import* is the thing a scan can see without a call graph, which is why the
 * rule is about imports.
 *
 * The symbol here is the fixture log, deliberately: it is the same import FX-3
 * carries, so this file and FX-3 differ only in **where they live**. That is
 * the whole of ruling 14.2's scope argument - the rule's subject is the two
 * shipped trees, and a fixture migration under tests/Fixtures/ is outside the
 * subject rather than exempt from the rule.
 *
 * @phase expand
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\TenantMigrations\FixtureMigrationLog;

return new class extends Migration
{
    public function up(): void
    {
        FixtureMigrationLog::record('fixture.imported_from_a_third_namespace');

        Schema::create('fixture_imports', function (Blueprint $table) {
            $table->string('note', 60)->nullable();
        });
    }
};
