<?php

/*
 * One ordered approval step of a definition (docs/database.md section
 * 6.15, T-020 AC-3, AC-9).
 *
 * `approver_type` and `quorum` are VARCHAR(30) with the application enums
 * `Flexipos\Workflow\Definitions\ApproverType` and
 * `Flexipos\Workflow\Definitions\Quorum` beside them. `(definition_id,
 * sequence)` is unique: AC-3 refuses a duplicate sequence at write time.
 * No timestamps, as documented - a step is replaced by deleting and
 * re-registering its definition, never edited in place (there is no edit
 * command in this task, Ruling 13).
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
        Schema::create('workflow_steps', function (Blueprint $table) {
            IdentifierColumn::primary($table);
            IdentifierColumn::required($table, 'definition_id');
            $table->smallInteger('sequence')->unsigned();
            $table->string('name', 100);
            $table->string('approver_type', 30);
            $table->json('approver_ref');
            $table->string('quorum', 30);
            $table->boolean('can_edit')->default(false);
            $table->json('can_skip_if')->nullable();
            $table->smallInteger('sla_hours')->unsigned()->nullable();
            $table->json('escalate_to')->nullable();

            $table->unique(['definition_id', 'sequence']);

            $table->foreign('definition_id')->references('id')->on('workflow_definitions')->cascadeOnDelete();
        });

        JsonValidity::enforce('workflow_steps', 'approver_ref', 'can_skip_if', 'escalate_to');
    }
};
