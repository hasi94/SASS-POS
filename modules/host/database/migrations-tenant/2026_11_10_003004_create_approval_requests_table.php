<?php

/*
 * Approval requests (docs/database.md section 6.7, T-030 AC-23, AC-24).
 *
 * The in-till, synchronous, PIN-gated override path, deliberately separate
 * from the asynchronous `workflow_*` engine (section 6.15). `type` is the
 * application enum `discount|refund|void|price_override|credit_limit|
 * negative_stock|custom` (this task accepts four), `status` is
 * `pending|approved|rejected|expired`, both `VARCHAR(30)`.
 *
 * **`reference_id` has no foreign key**: the reference is polymorphic
 * (`reference_type` names a sale or a goodwill refund), section 3.3's
 * documented exception.
 *
 * **`consumed_at` is this task's own addition** (D-44, F-18): AC-24's "marks
 * it consumed" needs a column to mark, and an approval is single-use.
 *
 * **`[SYNC]` columns are built present but inert** (D-28); `terminal_id` is
 * nullable and keyed, `device_id` carries no key.
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
        Schema::create('approval_requests', function (Blueprint $table) {
            IdentifierColumn::primary($table);
            $table->dateTime('created_at', 3);
            $table->dateTime('updated_at', 3);

            // [SYNC], built inert (D-28).
            $table->string('origin', 30)->default('online');
            IdentifierColumn::nullable($table, 'terminal_id');
            IdentifierColumn::nullable($table, 'device_id');
            $table->unsignedBigInteger('client_seq')->nullable();
            $table->dateTime('client_created_at', 3)->nullable();
            $table->string('sync_status', 30)->default('synced');
            $table->dateTime('synced_at', 3)->nullable();

            IdentifierColumn::required($table, 'branch_id');
            $table->string('type', 30);
            $table->string('reference_type', 50);
            IdentifierColumn::nullable($table, 'reference_id');
            IdentifierColumn::required($table, 'requested_by');
            $table->json('payload')->nullable();
            $table->string('status', 30)->default('pending');
            IdentifierColumn::nullable($table, 'decided_by');
            $table->dateTime('decided_at', 3)->nullable();
            $table->string('decision_method', 30)->nullable();
            $table->string('reason', 255)->nullable();
            $table->string('plugin_name', 100)->nullable();
            $table->dateTime('consumed_at', 3)->nullable();

            $table->index(['reference_type', 'reference_id']);
            $table->index(['branch_id', 'status']);

            $table->foreign('branch_id')->references('id')->on('branches');
            $table->foreign('terminal_id')->references('id')->on('terminals');
            $table->foreign('requested_by')->references('id')->on('employees');
            $table->foreign('decided_by')->references('id')->on('employees');
        });

        JsonValidity::enforce('approval_requests', 'payload');
    }
};
