<?php

/*
 * Sale holds (T-030 AC-17 to AC-20; this task's own table, D-44, F-18).
 *
 * The hold and recall trail. `docs/database.md` section 6.7 documents no such
 * table, but AC-17 to AC-19 need somewhere to record who parked a sale, who
 * picked it up, and the status a held sale came from, so that a recall returns
 * to it. Append-only: one row per `hold` or `recall` action.
 *
 * `action` is the application enum `hold|recall`; `from_status` and
 * `to_status` are sale statuses; all `VARCHAR(30)`. `terminal_id` and
 * `employee_id` are the terminal and employee that acted. `sale_id` cascades.
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
        Schema::create('sale_holds', function (Blueprint $table) {
            IdentifierColumn::primary($table);
            IdentifierColumn::required($table, 'sale_id');
            $table->string('action', 30);
            $table->string('from_status', 30);
            $table->string('to_status', 30);
            IdentifierColumn::required($table, 'terminal_id');
            IdentifierColumn::required($table, 'employee_id');
            $table->dateTime('created_at', 3);

            $table->index(['sale_id', 'created_at']);

            $table->foreign('sale_id')->references('id')->on('sales')->cascadeOnDelete();
            $table->foreign('terminal_id')->references('id')->on('terminals');
            $table->foreign('employee_id')->references('id')->on('employees');
        });
    }
};
