<?php

/*
 * The two foreign keys that make `products.tax_group_id` and
 * `products.purchase_tax_group_id` real (T-029 AC-1, AC-2, D-5, D-14).
 *
 * **One file holds both keys**, because the planner refuses a run that applies
 * a contract step while any non-provisioning tenant is more than one version
 * behind the version before that step; two consecutive contract files would
 * need two fleet runs (T-014 D-31, the same reasoning `add_branch_foreign_keys`
 * gives). This file's position in the tree is its version (T-011 D-6), so a
 * fleet below the version before it must be run up to that version first.
 *
 * **Keys only - no NOT NULL tightening, and that is the point** (D-14, F-3).
 * Tightening `tax_group_id` to NOT NULL is a column change against an existing
 * table, which the planner classifies as destructive and refuses to apply
 * while no backup facility exists, and tenant provisioning runs through that
 * same planner. Adding a key is not destructive. The tighten is a follow-up
 * row gated on backups; the application enforces non-null in the meantime.
 *
 * **Every data-clean check runs before anything is altered**, for the reasons
 * `add_branch_foreign_keys` states at length: on the SQLite stand-in adding a
 * key rebuilds the table and never re-checks the data, and no SQLite
 * migration runs in a transaction. For each column the file counts the
 * non-null values that match no `tax_groups.id`, and if there are any it
 * refuses with the table, the column and the **number** of offending rows,
 * never an id - the runner stores the message verbatim (T-011 D-10) - and
 * changes nothing. It never deletes, nulls or repairs a value; the tenant
 * fails alone and succeeds when re-run after the data is fixed.
 *
 * **Retry-safe.** A key already present on its column is skipped, so a run
 * that stopped between the two keys can be run again and finishes with exactly
 * one key per column.
 *
 * **Restrict only, no referential action**, the same default for every key so
 * nobody "helpfully" adds one later. No driver gate (T-014 measured SQLite's
 * rebuild keeping rows, keys and generated columns). No `down()`: tenant
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
    /**
     * Table, column, parent table, parent column - in the order the rebuilds
     * run.
     */
    private const KEYS = [
        ['products', 'tax_group_id', 'tax_groups', 'id'],
        ['products', 'purchase_tax_group_id', 'tax_groups', 'id'],
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
                'The product tax group foreign keys cannot be added: '.implode('; ', $offending).'.'
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
