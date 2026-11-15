<?php

/*
 * Customer communications (docs/database.md section 6.9, T-028 AC-35 to
 * AC-37).
 *
 * A communication has no update slice: it is a log of what happened, not
 * an editable record (Ruling 8). `occurred_at` defaults to the time of
 * write when not supplied, applied by the handler rather than a database
 * default, so a caller-supplied value is kept exactly.
 *
 * **`delivery_id` is deferred entirely** (D-6/D-19, F-2): a notification-
 * delivery table (docs/database.md section 6.16) does not exist. Added by
 * that module's own task, as an expand step followed by a separate
 * contract step - and that task's own column will be `BIGINT UNSIGNED`,
 * not a ULID, since section 6.16 makes `deliveries.id` a `BIGINT AUTO`
 * key.
 *
 * `customer_id`'s foreign key is `RESTRICT` (docs/database.md section 6.9
 * states no cascade here, unlike `customer_addresses`/`customer_notes`).
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
        Schema::create('customer_communications', function (Blueprint $table) {
            IdentifierColumn::primary($table);
            $table->dateTime('created_at', 3);
            $table->dateTime('updated_at', 3);
            IdentifierColumn::required($table, 'customer_id');
            $table->string('channel', 30);
            $table->string('direction', 30);
            $table->string('subject', 255)->nullable();
            $table->text('body')->nullable();
            $table->string('campaign_ref', 100)->nullable();
            IdentifierColumn::nullable($table, 'employee_id');
            $table->dateTime('occurred_at', 3);

            $table->index(['customer_id', 'occurred_at']);

            $table->foreign('customer_id')->references('id')->on('customers');
            $table->foreign('employee_id')->references('id')->on('employees');
        });
    }
};
