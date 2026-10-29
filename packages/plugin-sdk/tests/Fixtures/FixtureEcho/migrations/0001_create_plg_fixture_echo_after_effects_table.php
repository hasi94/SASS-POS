<?php

/*
 * fixture-echo's own idempotency marker table (T-019 plan Ruling 6, AC-19,
 * AC-29, AC-44). Not a reference plugin's table design - just enough to
 * prove `EchoAfter` can mark an outbox event handled exactly once.
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
        Schema::create('plg_fixture_echo_after_effects', function (Blueprint $table) {
            $table->char('event_id', 26)->primary();
            $table->string('point', 60);
        });
    }
};
