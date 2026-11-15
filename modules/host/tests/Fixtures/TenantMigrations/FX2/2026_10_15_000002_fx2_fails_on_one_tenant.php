<?php

/*
 * FX-2: an expand step that fails on exactly one tenant (T-008 step 8).
 *
 * AC-44's input. A run continues past a failed tenant (D-13), and the only way
 * to observe that is a migration that succeeds on two tenants and throws on the
 * third - the same file, the same run, one failure. Which tenant it fails on is
 * told to it by the test through FixtureMigrationLog, by database name, because
 * the database name is what a migration can see from inside the tenant
 * connection without reaching back into the master (AC-37).
 *
 * It throws **before** it creates anything, so a failed tenant is left exactly
 * as it was and AC-46's retry has something to prove: the table appears on that
 * tenant only when the migration is re-run.
 *
 * `count_id` exists for FX-4 to key onto, which is the only reason it is here.
 *
 * @phase expand
 */

use Flexipos\Kernel\Schema\Support\IdentifierColumn;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\TenantMigrations\FixtureMigrationLog;

return new class extends Migration
{
    public function up(): void
    {
        $database = (string) Schema::getConnection()->getDatabaseName();

        FixtureMigrationLog::record('fx2.ran_on', $database);

        if (FixtureMigrationLog::shouldFailOn($database)) {
            throw new RuntimeException('FX-2 was told to fail on the tenant database ['.$database.'].');
        }

        Schema::create('fx_two_marker', function (Blueprint $table) {
            IdentifierColumn::primary($table);
            IdentifierColumn::nullable($table, 'count_id');
            $table->string('note', 60);
        });
    }
};
