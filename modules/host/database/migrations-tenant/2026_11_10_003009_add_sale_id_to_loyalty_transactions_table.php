<?php

/*
 * `loyalty_transactions.sale_id` and its idempotency key (T-030 AC-45, D-34,
 * F-9) - discharging T-028's named debt to this task.
 *
 * T-028 deferred the column entirely because `sales` did not exist (T-028 D-6,
 * D-19). It exists now, so the column arrives nullable together with the
 * documented `unique (sale_id, type)` key. NULLs are distinct in a unique
 * index on both engines, so the many rows with no sale are unconstrained, and
 * two rows for one sale and one type are refused.
 *
 * **Expand only: the key onto `sales` is the next file**, a separate contract
 * step (T-014 D-31). An index on an existing table is allowed in expand;
 * the foreign-key rule restricts keys only. Nothing here changes an existing
 * column, and existing rows keep a NULL `sale_id`.
 *
 * `RecordLoyaltyTransaction` is not modified to accept it (D-5): wiring a real
 * `sale_id` through is left to whichever future caller wants automatic earning.
 *
 * @phase expand
 */

use Flexipos\Kernel\Schema\Support\IdentifierColumn;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loyalty_transactions', function (Blueprint $table) {
            IdentifierColumn::nullable($table, 'sale_id');

            $table->unique(['sale_id', 'type']);
        });
    }
};
