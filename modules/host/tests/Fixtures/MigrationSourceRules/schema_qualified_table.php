<?php

/*
 * AC-37's control: a schema-qualified table name.
 *
 * `database.table` names a second database from inside a migration that was
 * handed exactly one. It is the same boundary crossing as naming another
 * connection, with none of the configuration that would make it visible.
 *
 * @phase expand
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flexipos_master.fixture_widgets', function (Blueprint $table) {
            $table->string('name', 150);
        });
    }
};
