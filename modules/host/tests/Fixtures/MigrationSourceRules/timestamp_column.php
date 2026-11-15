<?php

/*
 * AC-35(c)'s control: a timestamp column in a table that is not one of the
 * eight Laravel system tables.
 *
 * A `TIMESTAMP` is second-precision and converts on read against the session
 * timezone; every business datetime in this design is `DATETIME(3)` in UTC. The
 * second declaration is the shorthand that creates two of them at once.
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
        Schema::create('fixture_events', function (Blueprint $table) {
            $table->timestamp('occurred_at');
            $table->timestamps();
        });
    }
};
