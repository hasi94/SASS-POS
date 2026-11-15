<?php

/*
 * AC-35(d)'s control: a migration that names a charset, a collation and a
 * storage engine.
 *
 * All three come from the connection configuration, where they are set once for
 * every table; a migration that names them is a table that stops agreeing with
 * the connection the moment the configuration changes.
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
        Schema::create('fixture_charsets', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->string('name', 150)->charset('latin1');
        });
    }
};
