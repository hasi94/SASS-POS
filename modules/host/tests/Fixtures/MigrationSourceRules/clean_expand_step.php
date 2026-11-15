<?php

/*
 * The negative control that carries the whole suite: a correct expand step.
 *
 * It creates a table, gives it a foreign key (allowed - the table is new and has
 * no rows), states its decimal precision, writes its datetimes at precision 3
 * and names no charset. Every rule must be silent on this file, or "nothing
 * found" above proves only that the detectors are broken.
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
        Schema::create('fixture_widgets', function (Blueprint $table) {
            IdentifierColumn::primary($table);
            IdentifierColumn::required($table, 'registry_key');
            $table->string('name', 150);
            $table->string('status', 30);
            $table->decimal('price', 18, 4);
            $table->dateTime('created_at', 3);
            $table->dateTime('updated_at', 3);

            $table->foreign('registry_key')->references('id')->on('fixture_registry');
        });
    }
};
