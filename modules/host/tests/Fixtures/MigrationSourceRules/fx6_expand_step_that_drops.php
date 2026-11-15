<?php

/*
 * FX-6: an expand step that calls a drop (AC-33's positive control).
 *
 * The table is not one this migration created, so the drop is against data that
 * existed before the deploy - which is the statement a contract step exists for.
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
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn('legacy_value');
        });
    }
};
