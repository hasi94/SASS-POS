<?php

/*
 * The foreign key `settings.updated_by` -> `employees.id` (T-011, owed since
 * T-008 D-5).
 *
 * `settings.updated_by` names the employee who last changed a setting. The
 * baseline created it as an unguarded CHAR(26) because `employees` did not
 * exist yet, and its docblock records the key as owed to the **employees**
 * module. T-010 shipped `employees` and deferred this key (its AC-10) behind
 * FP-304, because a contract step in the shipped tree used to kill the
 * planner's test corpus. This file is where the key is delivered; the
 * baseline's own docblocks still say "owed" because editing them would move
 * their checksums.
 *
 * **Contract phase.** Adding a key onto an existing table is a contract step
 * (AC-34): an application still writing unknown ids would start failing, so
 * every tenant must carry the expand steps first. **Two runs are needed**
 * against a fleet that is behind: the planner refuses a run containing this
 * step while any non-provisioning tenant is below the version before it, and
 * prints the remedy - `tenant:migrate --all --to=` the version before this
 * one - to be run first, then the run that includes this file. No version
 * number is written here (D-6): it is this file's position in the tree.
 *
 * **The data-clean check runs first, and it is the guard.** It counts the
 * `settings` rows whose non-null `updated_by` matches no `employees.id`, and
 * refuses before anything is altered:
 *
 * - on the SQLite stand-in, adding a key rebuilds the table, the grammar
 *   switches key checks off around the rebuild and never re-checks the data,
 *   and no SQLite migration is wrapped in a transaction - so without this
 *   check, dirty rows would be carried silently into the keyed table, and a
 *   rebuild that failed halfway would leave it half-altered;
 * - on an engine, a session with foreign_key_checks off would do the same.
 *
 * The comparison is exact (case matters), and a soft-deleted employee counts
 * as existing: the key refers to the row, not to whether it is live (D-8).
 * The failure names the **number** of offending rows and never a value from
 * the data (D-10): the runner stores this message verbatim. The migration
 * never deletes, nulls or repairs a value (D-7); the tenant fails alone, its
 * table unchanged, and succeeds when re-run after the data is fixed.
 *
 * **No driver gate** (D-5): T-010 step 3b measured SQLite's rebuild keeping
 * every row, the stored generated column, the existing registry key and the
 * null-scope uniqueness on a populated table. **No `down()`**: tenant
 * migrations are forward-only (T-008 D-8).
 *
 * @phase contract
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $offending = Schema::getConnection()->table('settings')
            ->whereNotNull('updated_by')
            ->whereNotExists(function (Builder $query) {
                $query->from('employees')->whereColumn('employees.id', 'settings.updated_by');
            })
            ->count();

        if ($offending > 0) {
            throw new RuntimeException(sprintf(
                'settings.updated_by: %d row(s) name an employee id that is not in employees.id, so the'
                .' foreign key cannot be added. Nothing was changed. Correct or clear those values'
                .' (rows where updated_by is not null and has no matching employees row), then run'
                .' this migration again.',
                $offending
            ));
        }

        Schema::table('settings', function (Blueprint $table) {
            $table->foreign('updated_by')->references('id')->on('employees');
        });
    }
};
