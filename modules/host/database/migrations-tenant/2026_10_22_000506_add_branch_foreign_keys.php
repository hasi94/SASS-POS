<?php

/*
 * The foreign keys that make branch ids, and the circular and audit columns of
 * the five organization tables, real (T-014, FP-209).
 *
 * **One file holds every key, nine of them, and the reason is the planner.**
 * It refuses a run that applies a contract step while any non-provisioning
 * tenant is more than one version behind the version before that step. Nine
 * consecutive contract files would need nine runs against a fleet at the last
 * table-creating version; one file needs two (T-014 D-31).
 *
 * **Where each key was owed, and where it is delivered here:**
 *
 * - `employee_roles.branch_id`, `employee_permissions.branch_id` and
 *   `employee_branches.branch_id` onto `branches.id`: each of those three
 *   migrations' docblocks says "FP-209 owes its foreign key" (T-010 D-1). Their
 *   docblocks are left as written, because editing them would move their
 *   checksums (T-014 AC-8).
 * - `number_sequences.branch_id` onto `branches.id`: owed to the branches module
 *   by T-008 D-5 and taken into this task by T-014 D-2. It is the fourth keyed
 *   table, a small configuration table whose duplicate-number hazard is the
 *   reason branch ids matter at all.
 * - `branches.default_location_id` onto `locations.id` and
 *   `terminals.current_device_id` onto `devices.id`: circular, because each
 *   column points at a table created by a later file, and a key onto a table
 *   that does not exist yet is refused by the engine. Their table files create
 *   the columns unguarded (T-014 D-32).
 * - `locations.created_by`, `locations.updated_by` and
 *   `terminal_credentials.revoked_by` onto `employees.id`: employee-audit
 *   columns the spec lists without a key and the plan adds one to (T-014
 *   Ruling 10 (e), D-45). They are constraints, not columns.
 *
 * **Four branch columns stay unguarded, on purpose** (T-014 D-2, AC-9):
 * `audit_logs.branch_id`, `outbox_events.branch_id`, `access_log.branch_id` and
 * `access_log.terminal_id`. They are append-only histories that may be large,
 * a key on them copies the table under a write lock, and a history row should
 * record what was claimed even if that branch was later wrong. Deferral, not
 * rejection; a follow-up row is owed.
 *
 * **Contract phase, and two runs against a fleet that is behind.** Adding a key
 * to an existing table is a contract step: an application still writing unknown
 * ids would start failing, so every tenant must carry the expand steps first.
 * The planner prints the remedy: run `tenant:migrate --all --to=` the version
 * before this file first, then the run that includes it. No version number is
 * written here (T-011 D-6): it is this file's position in the tree.
 *
 * **Every data-clean check runs before anything is altered.** For each key it
 * counts the rows whose non-null column matches no row of the parent, and if
 * any key has offenders the file refuses with every count in one message and
 * changes nothing:
 *
 * - on the SQLite stand-in, adding a key rebuilds the table, the grammar
 *   switches key checks off around the rebuild and never re-checks the data,
 *   and no SQLite migration is wrapped in a transaction - so without these
 *   checks, dirty rows would be carried silently into a keyed table, and a
 *   rebuild that failed halfway would leave a table half-altered. They are the
 *   only data guard there (T-011 Ruling 7, T-014 Ruling 14);
 * - on MySQL and MariaDB with key checks on, the ALTER re-checks the rows
 *   itself; the checks are kept for the clean, countable message and for one
 *   code path on every driver.
 *
 * The comparison is exact (case matters): a lower-case spelling of an existing
 * upper-case id counts as offending. The failure names the table, the column and
 * the **number** of offending rows and never a value from the data, because the
 * runner stores the message verbatim (T-011 D-10). The migration never deletes,
 * nulls or repairs a value; the tenant fails alone, its tables unchanged, and
 * succeeds when re-run after the data is fixed.
 *
 * **Retry-safe.** A key already present on its column is skipped, so a run that
 * stopped between two keys (on SQLite, which cannot roll one back) can be run
 * again and finishes with exactly one key per column.
 *
 * **Restrict only, no referential action.** `branch_id` is the base column of a
 * stored generated column in three of the four older tables, and MySQL and
 * MariaDB refuse SET NULL, SET DEFAULT and a cascading update on such a column,
 * so the engine default is the only legal choice there; the same default is
 * used for every key so nobody "helpfully" adds one later (T-014 Ruling 14,
 * D-4). On an engine, adding a key copies the table under a write lock; these
 * are small tables and nothing here claims the shop must be offline.
 *
 * **No driver gate** (T-014 step 1 measured SQLite's rebuild keeping every row,
 * every stored generated column, every existing key and the sentinel's
 * uniqueness, for all nine). **No `down()`**: tenant migrations are
 * forward-only (T-008 D-8).
 *
 * @phase contract
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Table, column, parent table, parent column - in the order the rebuilds
     * run.
     */
    private const KEYS = [
        ['number_sequences', 'branch_id', 'branches', 'id'],
        ['employee_roles', 'branch_id', 'branches', 'id'],
        ['employee_permissions', 'branch_id', 'branches', 'id'],
        ['employee_branches', 'branch_id', 'branches', 'id'],
        ['branches', 'default_location_id', 'locations', 'id'],
        ['terminals', 'current_device_id', 'devices', 'id'],
        ['locations', 'created_by', 'employees', 'id'],
        ['locations', 'updated_by', 'employees', 'id'],
        ['terminal_credentials', 'revoked_by', 'employees', 'id'],
    ];

    public function up(): void
    {
        $connection = Schema::getConnection();
        $offending = [];

        // Every data-clean check first.
        foreach (self::KEYS as [$table, $column, $parent, $parentColumn]) {
            $count = $connection->table($table)
                ->whereNotNull($column)
                ->whereNotExists(function (Builder $query) use ($table, $column, $parent, $parentColumn) {
                    $query->from($parent)->whereColumn($parent.'.'.$parentColumn, $table.'.'.$column);
                })
                ->count();

            if ($count > 0) {
                $offending[] = sprintf('%s.%s: %d row(s) name an id that is not in %s.%s', $table, $column, $count, $parent, $parentColumn);
            }
        }

        if ($offending !== []) {
            throw new RuntimeException(
                'The branch foreign keys cannot be added: '.implode('; ', $offending).'.'
                .' Nothing was changed. Correct or clear those values (rows where the column is not null and has'
                .' no matching parent row), then run this migration again.'
            );
        }

        foreach (self::KEYS as [$table, $column, $parent, $parentColumn]) {
            if ($this->hasKey($table, $column)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($column, $parent, $parentColumn) {
                $blueprint->foreign($column)->references($parentColumn)->on($parent);
            });
        }
    }

    /**
     * Does a foreign key already cover exactly this one column?
     */
    private function hasKey(string $table, string $column): bool
    {
        foreach (Schema::getForeignKeys($table) as $key) {
            if ($key['columns'] === [$column]) {
                return true;
            }
        }

        return false;
    }
};
