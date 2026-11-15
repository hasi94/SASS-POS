<?php

/*
 * The configurable approval rule (docs/database.md section 6.15, T-020
 * AC-1, AC-2).
 *
 * `conditions` is a flat AND of `{field, operator, value}` comparisons
 * (Ruling 4). `branch_ids`, when not null, is an implicit
 * `branch_id in branch_ids` condition, checked before `conditions`
 * (Ruling 4b). `entity_type` and `on_reject` are VARCHAR(30) with the
 * application enums `Flexipos\Contracts\Workflow\WorkflowEntityType` and
 * `Flexipos\Contracts\Workflow\WorkflowOnReject` beside them
 * (`SourceRules` AC-35(b)). `key` is unique: `RegisterWorkflowDefinition`
 * refuses a second definition under the same key before any insert
 * (AC-2), and this index is the backstop.
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
        Schema::create('workflow_definitions', function (Blueprint $table) {
            IdentifierColumn::primary($table);
            $table->dateTime('created_at', 3);
            $table->dateTime('updated_at', 3);
            $table->dateTime('deleted_at', 3)->nullable();
            IdentifierColumn::nullable($table, 'created_by');
            IdentifierColumn::nullable($table, 'updated_by');
            $table->string('key', 60);
            $table->string('name', 100);
            $table->string('entity_type', 30);
            $table->json('conditions');
            $table->json('branch_ids')->nullable();
            $table->smallInteger('priority');
            $table->string('on_reject', 30);
            $table->smallInteger('escalation_hours')->unsigned()->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('plugin_name', 100)->nullable();
            $table->integer('version')->unsigned()->default(1);

            $table->unique('key');
            $table->index(['entity_type', 'is_active', 'priority']);

            $table->foreign('created_by')->references('id')->on('employees');
            $table->foreign('updated_by')->references('id')->on('employees');
        });

        JsonValidity::enforce('workflow_definitions', 'conditions', 'branch_ids');
    }
};
