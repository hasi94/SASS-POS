<?php

/*
 * AC-37's control, master half: a master migration that names the tenant
 * connection.
 *
 * Which tenant? The master runs once, outside any tenant scope, so the tenant
 * connection there is whatever the last request left behind - or the sentinel
 * stanza with no host at all.
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
        Schema::connection('tenant')->create('fixture_tenant_table', function (Blueprint $table) {
            $table->string('name', 150);
        });
    }
};
