<?php

/*
 * AC-37's control, tenant half: a tenant migration that names the master
 * connection.
 *
 * The database is the tenant boundary. A tenant migration that reaches the
 * master runs 200 times against one database and makes the schema of every shop
 * depend on it.
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
        Schema::connection('master')->create('fixture_master_table', function (Blueprint $table) {
            $table->string('name', 150);
        });
    }
};
