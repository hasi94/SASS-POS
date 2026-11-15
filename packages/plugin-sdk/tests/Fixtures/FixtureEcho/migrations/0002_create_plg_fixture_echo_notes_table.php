<?php

/*
 * fixture-echo's second table (T-019 plan Ruling 6, AC-19, AC-44): proof
 * that a plugin's own migration may hold a foreign key **into** a core
 * table (`employee_id` -> `employees.id`), never the other way round.
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
        Schema::create('plg_fixture_echo_notes', function (Blueprint $table) {
            IdentifierColumn::primary($table);
            IdentifierColumn::nullable($table, 'employee_id');
            $table->text('note')->nullable();
            $table->dateTime('created_at', 3);

            $table->foreign('employee_id')->references('id')->on('employees');
        });
    }
};
