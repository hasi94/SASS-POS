<?php

/*
 * The one foreign key that makes `loyalty_transactions.sale_id` real (T-030
 * AC-45, D-34).
 *
 * **One file holds the one key, and it is this task's only contract file**
 * (T-014 D-31): the planner refuses a run that applies a contract step while
 * any non-provisioning tenant is more than one version behind the version
 * before that step, so a task's contract keys are grouped into a single file.
 * This file's position in the tree is its version (T-011 D-6), so a fleet below
 * the version before it must be run up to that version first.
 *
 * **Nothing destructive**: adding a key is not, and no column is tightened or
 * dropped (T-008 D-10).
 *
 * **The data-clean check runs before anything is altered**, for the reasons
 * `add_product_tax_group_foreign_keys` states at length: on the SQLite
 * stand-in adding a key rebuilds the table and never re-checks the data. The
 * file counts the non-null `sale_id` values that match no `sales.id`, and if
 * there are any it refuses with the table, the column and the **number** of
 * offending rows, never an id - the runner stores the message verbatim (T-011
 * D-10) - and changes nothing. It never deletes, nulls or repairs a value; the
 * tenant fails alone and succeeds when re-run after the data is fixed.
 *
 * **Retry-safe.** A key already present on the column is skipped.
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
    public function up(): void
    {
        $connection = Schema::getConnection();

        $count = $connection->table('loyalty_transactions')
            ->whereNotNull('sale_id')
            ->whereNotExists(function (Builder $query) {
                $query->from('sales')->whereColumn('sales.id', 'loyalty_transactions.sale_id');
            })
            ->count();

        if ($count > 0) {
            throw new RuntimeException(
                'The loyalty sale foreign key cannot be added: '
                .sprintf('loyalty_transactions.sale_id: %d row(s) name an id that is not in sales.id', $count).'.'
                .' Nothing was changed. Correct or clear those values (rows where the column is not null and has'
                .' no matching parent row), then run this migration again.'
            );
        }

        if ($this->hasKey()) {
            return;
        }

        Schema::table('loyalty_transactions', function (Blueprint $blueprint) {
            $blueprint->foreign('sale_id')->references('id')->on('sales');
        });
    }

    /**
     * Does a foreign key already cover exactly this one column?
     */
    private function hasKey(): bool
    {
        foreach (Schema::getForeignKeys('loyalty_transactions') as $key) {
            if ($key['columns'] === ['sale_id']) {
                return true;
            }
        }

        return false;
    }
};
