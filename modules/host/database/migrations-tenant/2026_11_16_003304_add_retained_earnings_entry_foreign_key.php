<?php

/*
 * The one foreign key that makes `fiscal_years.retained_earnings_entry_id`
 * real (T-033 AC-75 to AC-78, plan Ruling 10, D-42): the column onto
 * `journal_entries.id`, RESTRICT. T-032 built the column as a plain nullable
 * identifier because `journal_entries` did not exist (T-032 D-28); this file
 * is the key it owed.
 *
 * **Nothing destructive**: adding a key is not, and no column is tightened or
 * dropped (T-008 D-10, D-11).
 *
 * **The data-clean check runs before anything is altered**, for the reasons
 * `add_owed_account_foreign_keys` states at length: on the SQLite stand-in
 * adding a key rebuilds the table and never re-checks the data. The file
 * counts the non-null values that match no `journal_entries.id` and, if any,
 * refuses naming the table, the column and the **number** of offending rows,
 * never an id - the runner stores the message verbatim (T-011 D-10) - and
 * changes nothing. It never deletes, nulls or repairs a value; the tenant
 * fails alone and succeeds when re-run after the data is fixed. The comparison
 * is exact: a lower-case spelling of an existing upper-case id counts as
 * offending.
 *
 * **It names `fiscal_years` literally, on purpose** (D-42). T-032's AC-60 scan
 * reports any migration that names `table('fiscal_years')` outside the seed,
 * and the owed-account file passes it only by handing table names through
 * variables, which the scan cannot read. Reading `fiscal_years` through a
 * variable here would be evading the rule; this file is instead named in the
 * scan's exemption list, where a reviewer can see it.
 *
 * **Rebuild on the stand-in.** SQLite adds the key by rebuilding
 * `fiscal_years`, which `fiscal_periods` references with a cascade. The
 * migration tests assert every year, every period and every existing key
 * survive that rebuild.
 *
 * **Retry-safe.** A key already present on the column is skipped. **Restrict
 * only, no referential action.** No driver gate. No `down()`: tenant
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
        $connection = Schema::getConnection();

        // The data-clean check first.
        $count = $connection->table('fiscal_years')
            ->whereNotNull('retained_earnings_entry_id')
            ->whereNotExists(function (Builder $query) {
                $query->from('journal_entries')->whereColumn('journal_entries.id', 'fiscal_years.retained_earnings_entry_id');
            })
            ->count();

        if ($count > 0) {
            throw new RuntimeException(
                'The retained earnings entry foreign key cannot be added: fiscal_years.retained_earnings_entry_id:'
                .sprintf(' %d row(s) name an id that is not in journal_entries.id.', $count)
                .' Nothing was changed. Correct or clear those values (rows where the column is not null and has'
                .' no matching parent row), then run this migration again.'
            );
        }

        if ($this->hasKey()) {
            return;
        }

        Schema::table('fiscal_years', function (Blueprint $blueprint) {
            $blueprint->foreign('retained_earnings_entry_id')->references('id')->on('journal_entries');
        });
    }

    /**
     * Does a foreign key already cover exactly this one column?
     */
    private function hasKey(): bool
    {
        foreach (Schema::getForeignKeys('fiscal_years') as $key) {
            if ($key['columns'] === ['retained_earnings_entry_id']) {
                return true;
            }
        }

        return false;
    }
};
