<?php

/*
 * The outbox: this shop's outward edges (docs/database.md section 6.21).
 *
 * Not for module-to-module communication - that is a function call inside a
 * transaction - but for notifications, webhooks, the print relay, plugins and
 * the platform analytics feed. A business row and its event are written in **one
 * transaction**, which is the property that makes this table the seam for the
 * read side the owner chose (AC-28): either both exist or neither does.
 *
 * **Exactly the columns section 6.21 lists, and nothing else** (D-1, AC-27).
 * There is no projection-state table in this baseline and **no claim-mark
 * column** on this table. `docs/patterns.md` section 5 requires a *measurement*
 * before a projection exists and there is none; the relay FP-212 ships first
 * needs no mark either, because it selects `status = 'pending'` ordered by
 * `occurred_at` and the (status, occurred_at) index below already serves that.
 *
 * When a mark is eventually needed - several relay workers - it is a **claim
 * mark**: a nullable mark column on the event row itself. Never a cursor and
 * never a `position` column, because `id` here is a ULID, and a reader resuming
 * from the last id it saw will miss an event committed later with a smaller id.
 * That ruling is recorded so the alternative cannot be re-litigated by whoever
 * meets the ordering problem first.
 *
 * `occurred_at` is DATETIME(3): a POS writes several events a second, and
 * second precision would make their order unrecoverable. A new row defaults to
 * `pending` with zero attempts, so a producer that writes only the business
 * columns still produces a publishable event.
 *
 * **`branch_id` has no foreign key** (D-5): `branches` does not exist yet. The
 * four unguarded columns of this baseline are
 * `number_sequences.branch_id`, `audit_logs.branch_id` and
 * `outbox_events.branch_id`, owed to the **branches** module, and
 * `settings.updated_by`, owed to the **employees** module. Until the owing
 * module adds each key - as a *contract*-phase migration, which AC-34's static
 * rule enforces - nothing in the database stops a bad id in these columns.
 *
 * @phase expand
 */

use Flexipos\Kernel\Schema\Support\IdentifierColumn;
use Flexipos\Kernel\Schema\Support\JsonValidity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox_events', function (Blueprint $table) {
            IdentifierColumn::primary($table);
            $table->string('type', 80);
            $table->unsignedSmallInteger('version')->default(1);
            IdentifierColumn::nullable($table, 'branch_id');
            $table->string('aggregate_type', 80);
            IdentifierColumn::required($table, 'aggregate_id');
            $table->json('payload')->nullable();
            $table->dateTime('occurred_at', 3);
            IdentifierColumn::nullable($table, 'correlation_id');
            IdentifierColumn::nullable($table, 'causation_id');
            $table->json('actor')->nullable();
            $table->string('status', 30)->default('pending');
            $table->dateTime('published_at', 3)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();

            $table->index(['status', 'occurred_at']);
            $table->index(['aggregate_type', 'aggregate_id']);
        });

        JsonValidity::enforce('outbox_events', 'payload', 'actor');
    }
};
