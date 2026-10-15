<?php

/*
 * FX-3: an offline step that observes the tenant's mapping status from inside
 * `up()` (T-008 step 8).
 *
 * AC-52's input, and the observation point is the whole criterion: the mapping
 * status is `migrating` *during* an offline migration and `active` again
 * afterwards, so a test that looked at the row after the run could not tell a
 * correct implementation from one that never set it. The status itself is read
 * by the probe the test installs - it lives in the master, and a migration that
 * named that connection would violate AC-37.
 *
 * `@requires-offline` is read from this comment by MigrationCatalogue, which is
 * why it is a declaration rather than a statement: whether a shop has to stop
 * is a property of the change, not something the file can discover at run time.
 *
 * @phase expand
 *
 * @requires-offline
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\TenantMigrations\FixtureMigrationLog;

return new class extends Migration
{
    public function up(): void
    {
        FixtureMigrationLog::observe('fx3.status');
        FixtureMigrationLog::record('fx3.ran_on', Schema::getConnection()->getDatabaseName());

        Schema::create('fx_offline_marker', function (Blueprint $table) {
            $table->string('key', 60)->primary();
            $table->string('value', 60)->nullable();
        });
    }
};
