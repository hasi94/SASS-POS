<?php

/*
 * Purchase requests (docs/database.md section 6.10, T-034 AC-19 to AC-39).
 *
 * `status` is `draft|pending_approval|approved|rejected|converted|cancelled`,
 * a `VARCHAR(30)` with an application enum (Ruling 4). `number` is `PR`
 * numbered from `DocumentNumbers`, taken last in the creating transaction, and
 * is unique and NOT NULL because a draft has one too (D-10).
 * `workflow_instance_id` is set at submit and cleared when the request returns
 * to draft.
 *
 * Nothing is deferred here: a request carries no money and no `[CF]` column.
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
        Schema::create('purchase_requests', function (Blueprint $table) {
            IdentifierColumn::primary($table);
            $table->dateTime('created_at', 3);
            $table->dateTime('updated_at', 3);
            IdentifierColumn::nullable($table, 'created_by');
            IdentifierColumn::nullable($table, 'updated_by');

            $table->string('number', 30);
            IdentifierColumn::required($table, 'branch_id');
            IdentifierColumn::required($table, 'location_id');
            IdentifierColumn::required($table, 'requested_by');
            $table->date('required_date')->nullable();
            $table->string('status', 30);
            IdentifierColumn::nullable($table, 'workflow_instance_id');
            $table->text('notes')->nullable();

            $table->unique('number');
            $table->index(['branch_id', 'status']);
            $table->index('status');

            $table->foreign('created_by')->references('id')->on('employees');
            $table->foreign('updated_by')->references('id')->on('employees');
            $table->foreign('branch_id')->references('id')->on('branches');
            $table->foreign('location_id')->references('id')->on('locations');
            $table->foreign('requested_by')->references('id')->on('employees');
            $table->foreign('workflow_instance_id')->references('id')->on('workflow_instances');
        });
    }
};
