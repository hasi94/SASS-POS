<?php

/*
 * One approver's assignment and decision on one step (docs/database.md
 * section 6.15, T-020 AC-9, AC-12, AC-17).
 *
 * `status` is VARCHAR(30) with the application enum
 * `Flexipos\Workflow\Support\TaskStatus` beside it. `delegated_to` is
 * present and writable, as documented, but nothing writes it in this task
 * (out of scope: delegation). `notified_at`/`reminded_at` are present and
 * writable but nothing reads or writes them (AC-22, D-9).
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
        Schema::create('workflow_tasks', function (Blueprint $table) {
            IdentifierColumn::primary($table);
            $table->dateTime('created_at', 3);
            $table->dateTime('updated_at', 3);
            IdentifierColumn::required($table, 'instance_id');
            $table->smallInteger('step_sequence')->unsigned();
            IdentifierColumn::nullable($table, 'assignee_employee_id');
            IdentifierColumn::nullable($table, 'assignee_role_id');
            $table->string('status', 30);
            IdentifierColumn::nullable($table, 'decided_by');
            $table->dateTime('decided_at', 3)->nullable();
            $table->text('comment')->nullable();
            IdentifierColumn::nullable($table, 'delegated_to');
            $table->dateTime('notified_at', 3)->nullable();
            $table->dateTime('reminded_at', 3)->nullable();

            $table->index(['assignee_employee_id', 'status']);
            $table->index(['instance_id', 'step_sequence']);

            $table->foreign('instance_id')->references('id')->on('workflow_instances')->cascadeOnDelete();
            $table->foreign('assignee_employee_id')->references('id')->on('employees');
            $table->foreign('assignee_role_id')->references('id')->on('roles');
            $table->foreign('decided_by')->references('id')->on('employees');
            $table->foreign('delegated_to')->references('id')->on('employees');
        });
    }
};
