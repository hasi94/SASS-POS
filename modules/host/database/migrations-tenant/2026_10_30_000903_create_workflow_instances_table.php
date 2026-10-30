<?php

/*
 * One running (or finished) approval chain (docs/database.md section
 * 6.15, T-020 AC-14, AC-19 to AC-21).
 *
 * `status` is VARCHAR(30) with the application enum
 * `Flexipos\Contracts\Workflow\WorkflowInstanceStatus` beside it.
 * `context` freezes the evaluation context's non-null fields at the
 * moment the instance was created (Ruling 2), so a later change to the
 * caller's own data never moves an instance already in flight.
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
        Schema::create('workflow_instances', function (Blueprint $table) {
            IdentifierColumn::primary($table);
            $table->dateTime('created_at', 3);
            $table->dateTime('updated_at', 3);
            IdentifierColumn::required($table, 'definition_id');
            $table->integer('definition_version')->unsigned();
            $table->string('entity_type', 30);
            IdentifierColumn::required($table, 'entity_id');
            IdentifierColumn::nullable($table, 'branch_id');
            IdentifierColumn::required($table, 'requested_by');
            $table->decimal('amount', 18, 4)->nullable();
            $table->smallInteger('current_step')->unsigned();
            $table->string('status', 30);
            $table->dateTime('started_at', 3);
            $table->dateTime('completed_at', 3)->nullable();
            $table->dateTime('sla_due_at', 3)->nullable();
            $table->json('context');

            $table->index(['entity_type', 'entity_id']);
            $table->index(['status', 'sla_due_at']);
            $table->index('requested_by');

            $table->foreign('definition_id')->references('id')->on('workflow_definitions');
            $table->foreign('branch_id')->references('id')->on('branches');
            $table->foreign('requested_by')->references('id')->on('employees');
        });

        JsonValidity::enforce('workflow_instances', 'context');
    }
};
