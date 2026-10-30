<?php

/*
 * The append-only audit trail of every instance's transitions
 * (docs/database.md section 6.15, T-020 AC-18, Ruling 8).
 *
 * `event` is VARCHAR(30) with the application enum
 * `Flexipos\Workflow\Support\WorkflowEventType` beside it. The primary key
 * is an auto-incrementing BIGINT, not a ULID: this is an append-only log
 * and the monotonic key is also the cheapest read order, the T-019
 * `plugin_execution_logs` precedent. `Flexipos\Workflow\Support\
 * WorkflowEventLog` is the only writer, and it only inserts.
 *
 * `actor_employee_id` gets a foreign key to `employees` here (D-12),
 * which docs/database.md section 6.15 leaves as a bare nullable column -
 * recorded as an as-built note in step 15.
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
        Schema::create('workflow_events', function (Blueprint $table) {
            $table->id();
            IdentifierColumn::required($table, 'instance_id');
            IdentifierColumn::nullable($table, 'task_id');
            $table->string('event', 30);
            IdentifierColumn::nullable($table, 'actor_employee_id');
            $table->text('comment')->nullable();
            $table->json('data')->nullable();
            $table->dateTime('created_at', 3);

            $table->foreign('instance_id')->references('id')->on('workflow_instances')->cascadeOnDelete();
            $table->foreign('task_id')->references('id')->on('workflow_tasks');
            $table->foreign('actor_employee_id')->references('id')->on('employees');
        });

        JsonValidity::enforce('workflow_events', 'data');
    }
};
