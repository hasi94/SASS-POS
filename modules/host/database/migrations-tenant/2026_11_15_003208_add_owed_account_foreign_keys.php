<?php

/*
 * The five foreign keys that make the owed account columns real (T-032 AC-53,
 * D-21): `products.income_account_id`, `products.cogs_account_id`,
 * `products.inventory_account_id`, `categories.income_account_id` and
 * `customers.receivable_account_id`, each onto `accounts.id`.
 *
 * **One file holds every key, and it is this task's only contract file**
 * (T-014 D-31): the planner refuses a run that applies a contract step while
 * any non-provisioning tenant is more than one version behind the version
 * before that step, so five consecutive contract files would need five fleet
 * runs. This file's position in the tree is its version (T-011 D-6), so a
 * fleet below the version before it must be run up to that version first.
 *
 * **Nothing destructive**: adding a key is not, and no column is tightened or
 * dropped (T-008 D-10, D-11).
 *
 * **Every data-clean check runs before anything is altered**, for the reasons
 * `add_branch_foreign_keys` states at length: on the SQLite stand-in adding a
 * key rebuilds the table and never re-checks the data. For each key the file
 * counts the non-null values that match no `accounts.id`, and if any key has
 * offenders it refuses with every table, column and the **number** of
 * offending rows, never an id - the runner stores the message verbatim (T-011
 * D-10) - and changes nothing. It never deletes, nulls or repairs a value; the
 * tenant fails alone and succeeds when re-run after the data is fixed. The
 * comparison is exact: a lower-case spelling of an existing upper-case id
 * counts as offending.
 *
 * **Retry-safe.** A key already present on its column is skipped.
 *
 * **Restrict only, no referential action.** No driver gate. No `down()`:
 * tenant migrations are forward-only (T-008 D-8).
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
     * Table and column; every key references `accounts.id`.
     */
    private const KEYS = [
        ['products', 'income_account_id'],
        ['products', 'cogs_account_id'],
        ['products', 'inventory_account_id'],
        ['categories', 'income_account_id'],
        ['customers', 'receivable_account_id'],
    ];

    public function up(): void
    {
        $connection = Schema::getConnection();
        $offending = [];

        // Every data-clean check first.
        foreach (self::KEYS as [$table, $column]) {
            $count = $connection->table($table)
                ->whereNotNull($column)
                ->whereNotExists(function (Builder $query) use ($table, $column) {
                    $query->from('accounts')->whereColumn('accounts.id', $table.'.'.$column);
                })
                ->count();

            if ($count > 0) {
                $offending[] = sprintf('%s.%s: %d row(s) name an id that is not in accounts.id', $table, $column, $count);
            }
        }

        if ($offending !== []) {
            throw new RuntimeException(
                'The owed account foreign keys cannot be added: '.implode('; ', $offending).'.'
                .' Nothing was changed. Correct or clear those values (rows where the column is not null and has'
                .' no matching parent row), then run this migration again.'
            );
        }

        foreach (self::KEYS as [$table, $column]) {
            if ($this->hasKey($table, $column)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($column) {
                $blueprint->foreign($column)->references('id')->on('accounts');
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
